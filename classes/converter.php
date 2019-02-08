<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Class for converting files between different file formats using Microsoft OneDrive drive.
 *
 * @package    fileconverter_onedrive
 * @copyright  2018 University of Nottingham
 * @author     Neill Magill <neill.magill@nottingham.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace fileconverter_onedrive;

defined('MOODLE_INTERNAL') || die();

use stored_file;
use moodle_exception;
use moodle_url;
use \core_files\conversion;

/**
 * Class for converting files between different formats using unoconv.
 *
 * @package    fileconverter_onedrive
 * @copyright  2018 University of Nottingham
 * @author     Neill Magill <neill.magill@nottingham.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class converter implements \core_files\converter_interface {
    /** @var array $supported Map of output formats to input formats. */
    private static $supported = array(
        'pdf' => ['csv', 'doc', 'docx', 'odp', 'ods', 'odt', 'pot', 'potm', 'potx', 'pps', 'ppsx', 'ppsxm', 'ppt', 'pptm', 'pptx',
            'rtf', 'xls', 'xlsx'],
    );

    /**
     * Convert a document to a new format and return a conversion object relating to the conversion in progress.
     *
     * @param \core_files\conversion $conversion The file to be converted
     * @return this
     */
    public function start_document_conversion(\core_files\conversion $conversion) {
        global $CFG, $SITE;

        $file = $conversion->get_sourcefile();
        $format = $conversion->get('targetformat');

        $issuerid = get_config('fileconverter_onedrive', 'issuerid');
        if (empty($issuerid)) {
            $conversion->set('status', conversion::STATUS_FAILED);
            $conversion->set('statusmessage', get_string('test_issuernotset', 'fileconverter_onedrive'));
            return $this;
        }

        $issuer = \core\oauth2\api::get_issuer($issuerid);
        if (empty($issuer)) {
            $conversion->set('status', conversion::STATUS_FAILED);
            $conversion->set('statusmessage', get_string('test_issuerinvalid', 'fileconverter_onedrive'));
            return $this;
        }
        $client = \core\oauth2\api::get_system_oauth_client($issuer);

        $service = new \fileconverter_onedrive\rest($client);

        $contenthash = $file->get_contenthash();

        $originalname = $file->get_filename();
        if (strpos($originalname, '.') === false) {
            $conversion->set('status', conversion::STATUS_FAILED);
            return $this;
        }
        $importextension = substr($originalname, strrpos($originalname, '.') + 1);

        $filecontent = $file->get_content();
        $filesize = $file->get_filesize();
        $filemimetype = $file->get_mimetype();

        // First upload the file.
        // We use a path that should be unique to the Moodle site, and not clash with the onedrive repository plugin.
        $path = '_fileconverter_onedrive_' . $SITE->shortname;
        $params = [
            'filename' => urlencode("$path/$contenthash.$importextension"),
        ];
        $behaviour = ['item' => ["@microsoft.graph.conflictBehavior" => "rename"]];

        $response = $service->call('create_upload', $params, json_encode($behaviour));

        if (empty($response->uploadUrl)) {
            $conversion->set('status', conversion::STATUS_FAILED);
            $conversion->set('statusmessage', get_string('uploadprepfailed', 'fileconverter_onedrive'));
            return $this;
        }

        // Try each curl class in turn until we succeed.
        // First attempt an upload with no auth headers (will work for personal onedrive accounts).
        // If that fails, try an upload with the auth headers (will work for work onedrive accounts).
        foreach ([new \curl(), $client] as $curlinstance) {
            $curlinstance->setHeader('Content-type: ' . $filemimetype);
            $curlinstance->setHeader('Content-Range: bytes 0-' . ($filesize - 1) . '/' . $filesize);
            $uploadoptions = array(
                'CURLOPT_PUT' => 1,
                'CURLOPT_INFILESIZE' => $filesize,
                'CURLOPT_INFILE' => $file->get_content_file_handle(),
            );
            $upload = $curlinstance->put($response->uploadUrl, [], $uploadoptions);
            if ($curlinstance->errno == 0) {
                $upload = json_decode($upload);
            }
            if (!empty($upload->id)) {
                // We can stop now - there is a valid file returned.
                break;
            }
        }

        if (empty($upload->id)) {
            $conversion->set('status', conversion::STATUS_FAILED);
            $conversion->set('statusmessage', get_string('uploadfailed', 'fileconverter_onedrive'));
            return $this;
        }

        $fileid = $upload->id;

        // Convert the file.
        $convertparams = [
            'itemid' => $fileid,
            'format' => $format,
        ];
        $headers = $service->call('convert', $convertparams);

        $downloadurl;
        // Microsoft OneDrive returns the location of the converted file in the Location header.
        foreach ($headers as $header) {
            if (strpos($header, 'Location:') === 0) {
                $downloadurl = trim(substr($header, strpos($header, ':') + 1));
            }
        }

        if (empty($downloadurl)) {
            $conversion->set('status', conversion::STATUS_FAILED);
            $conversion->set('statusmessage', get_string('nodownloadurl', 'fileconverter_onedrive'));
            return $this;
        }

        $sourceurl = new moodle_url($downloadurl);
        $source = $sourceurl->out(false);

        $tmp = make_request_directory();
        $downloadto = $tmp . '/' . $fileid . '.' . $format;

        $options = ['filepath' => $downloadto, 'timeout' => 15, 'followlocation' => true, 'maxredirs' => 5];
        $success = $client->download_one($source, null, $options);

        if ($success) {
            $conversion->store_destfile_from_path($downloadto);
            $conversion->set('status', conversion::STATUS_COMPLETE);
            $conversion->update();
        } else {
            $conversion->set('status', conversion::STATUS_FAILED);
            $conversion->set('statusmessage', get_string('downloadfailed', 'fileconverter_onedrive'));
        }
        // Cleanup.
        $deleteparams = [
            'itemid' => $fileid
        ];
        $service->call('delete', $deleteparams);

        return $this;
    }

    /**
     * Generate and serve the test document.
     *
     * @return stored_file
     */
    public function serve_test_document() {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $filerecord = [
            'contextid' => \context_system::instance()->id,
            'component' => 'test',
            'filearea' => 'fileconverter_onedrive',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'conversion_test.docx'
        ];

        // Get the fixture doc file content and generate and stored_file object.
        $fs = get_file_storage();
        $testdocx = $fs->get_file($filerecord['contextid'], $filerecord['component'], $filerecord['filearea'],
                $filerecord['itemid'], $filerecord['filepath'], $filerecord['filename']);

        if (!$testdocx) {
            $fixturefile = dirname(__DIR__) . '/tests/fixtures/source.docx';
            $testdocx = $fs->create_file_from_pathname($filerecord, $fixturefile);
        }

        $conversion = new \core_files\conversion(0, (object) [
            'targetformat' => 'pdf',
        ]);

        $conversion->set_sourcefile($testdocx);
        $conversion->create();

        // Convert the doc file to pdf and send it direct to the browser.
        $this->start_document_conversion($conversion);

        if ($conversion->get('status') === conversion::STATUS_FAILED) {
            $errors = array_merge($conversion->get_errors(), ['statusmessage' => $conversion->get('statusmessage')]);
            print_error('conversionfailed', 'fileconverter_onedrive', '', $errors);
        }

        $testfile = $conversion->get_destfile();
        readfile_accel($testfile, 'application/pdf', true);
    }

    /**
     * Poll an existing conversion for status update.
     *
     * @param conversion $conversion The file to be converted
     * @return $this;
     */
    public function poll_conversion_status(conversion $conversion) {
        return $this;
    }

    /**
     * Whether the plugin is configured and requirements are met.
     *
     * @return bool
     */
    public static function are_requirements_met() {
        $issuerid = get_config('fileconverter_onedrive', 'issuerid');
        if (empty($issuerid)) {
            return false;
        }

        $issuer = \core\oauth2\api::get_issuer($issuerid);
        if (empty($issuer)) {
            return false;
        }

        if (!$issuer->get('enabled')) {
            return false;
        }

        if (!$issuer->is_system_account_connected()) {
            return false;
        }

        return true;
    }

    /**
     * Whether a file conversion can be completed using this converter.
     *
     * @param string $from The source type
     * @param string $to The destination type
     * @return bool
     */
    public static function supports($from, $to) {
        if (!isset(self::$supported[$to])) {
            // The output is not supported.
            return false;
        }
        if (array_search($from, self::$supported[$to]) === false) {
            // The input is not supported by the output.
            return false;
        }
        return true;
    }

    /**
     * A list of the supported conversions.
     *
     * @return string
     */
    public function get_supported_conversions() {
        $supports = '';
        foreach (self::$supported as $output => $inputs) {
            $supports .= implode(', ', $inputs);
            $supports .= " => $output;\n\n";
        }
        return $supports;
    }
}
