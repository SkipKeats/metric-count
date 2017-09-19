<?php

require_once __DIR__ . '/MetricsTaxonomy.class.php';
require_once __DIR__ . '/MetricsTaxonomiesTree.class.php';

use \Aws\Common\Aws;

/**
 * Class MetricsCounter
 */
class MetricsCounterNonFed
{
    /**
     *
     */
    const LOCK_TITLE = 'metrics_cron_lock';
    /**
     * cURL handler
     * @var resource
     */
    private $ch;
    /**
     * cURL headers
     * @var array
     */
    private $ch_headers;
    /**
     * @var string
     */
    private $idm_json_url = '';
    /**
     * @var mixed|string
     */
    private $ckanUrl = '';
    /**
     * @var mixed|string
     */
    private $ckanApiUrl = '';
    /**
     * @var int
     */
    private $stats = 0;
    /**
     * @var int
     */
    private $statsByMonth = 0;
    /**
     * @var array
     */
    private $results = array();
    /**
     * @var WP_DB
     */
    private $wpdb;
    /**
     * @var array
     */
    private $counts = array();

    /**
     *
     */
    function __construct()
    {
        $this->idm_json_url = 'http://catalog.data.gov/api/3/action/organization_list?all_fields=true';

        $this->ckanUrl = get_option('ckan_access_pt')?:'//catalog.data.gov/';
        $this->ckanUrl = str_replace(array('http:', 'https:'), array('', ''), $this->ckanUrl);

        $this->ckanApiUrl = get_option('ckan_api_endpoint') ?: '//catalog.data.gov/';
        $this->ckanApiUrl = str_replace(array('http:', 'https:'), array('', ''), $this->ckanApiUrl);


        global $wpdb;
        $this->wpdb = $wpdb;

        // Create cURL object.
        $this->ch = curl_init();
        // Follow any Location: headers that the server sends.
        curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, true);
        // However, don't follow more than five Location: headers.
        curl_setopt($this->ch, CURLOPT_MAXREDIRS, 5);
        // Automatically set the Referrer: field in requests
        // following a Location: redirect.
        curl_setopt($this->ch, CURLOPT_AUTOREFERER, true);
        // Return the transfer as a string instead of dumping to screen.
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        // If it takes more than 45 seconds, fail
        curl_setopt($this->ch, CURLOPT_TIMEOUT, 45);
        // We don't want the header (use curl_getinfo())
        curl_setopt($this->ch, CURLOPT_HEADER, false);
        // Track the handle's request string
        curl_setopt($this->ch, CURLINFO_HEADER_OUT, true);
        // Attempt to retrieve the modification date of the remote document.
        curl_setopt($this->ch, CURLOPT_FILETIME, true);

        // Initialize cURL headers
        $this->set_headers();
    }

    /**
     * Sets the custom cURL headers.
     * @access    private
     * @return    void
     * @since     Version 0.1.0
     */
    private function set_headers()
    {
        $date = new DateTime(null, new DateTimeZone('UTC'));
        $this->ch_headers = array(
            'Date: ' . $date->format('D, d M Y H:i:s') . ' GMT', // RFC 1123
            'Accept-Charset: utf-8',
            'Accept-Encoding: gzip'
        );
    }

    /**
     *
     */
    public function updateMetrics()
    {
        echo PHP_EOL . date("(Y-m-d H:i:s)") . '(metrics-cron) Started for non-federal' . PHP_EOL;
//        if (!$this->checkLock()) {
//            echo "Locked: another instance of metrics script is already running. Please try again later";
//
//            return;
//        }

        set_time_limit(60 * 60 * 5);  //  5 hours

//        If previous cron script failed, we need to remove trash
        // $this->cleaner();

//    Get latest taxonomies from http://idm.data.gov/fed_agency.json
        $AllCategories = $this->ckan_metric_get_organizations();

        /** @var MetricsTaxonomy $RootOrganization */
        foreach ($AllCategories as $OneOrganization) {
//        skip broken structures

            // $solr_terms = join('+OR+', $RootOrganization->getTerms());
            $solr_terms = $OneOrganization['name'];
            $solr_query = "organization:(({$solr_terms}))";

            /**
             * Collect statistics and create data for ROOT organization
             */
            $parent_nid = $this->create_metric_content(
                $OneOrganization['organization_type'],
                $OneOrganization['title'],
                $OneOrganization['name'],
                $solr_query,
                0,
                1,
                '',
                0
            );

            /**
             * Check if there are some Department/Agency level datasets
             * without publisher!
             */
            $this->create_metric_content_department_level_without_publisher(
                $OneOrganization,
                $parent_nid
            );

            /**
             * Get publishers by organization
             */
            $this->create_metric_content_by_publishers(
                $OneOrganization,
                $parent_nid
            );

        }

        $this->write_metrics_csv_and_xls();

        echo '<hr />get count: ' . $this->stats . ' times<br />';
        echo 'get count by month: ' . $this->statsByMonth . ' times<br />';

//        Publish new metrics
        $this->publishNewMetrics();

        $this->unlock();
    }

    /**
     * @return mixed
     */
    private function ckan_metric_get_organizations()
    {
        $AllCategories = array();
        $response = $this->curl_get($this->idm_json_url);
        $body = json_decode($response, true);
        $organizations = $body['result'];
        foreach ($organizations as $organization) {
            if ($organization['organization_type'] != "Federal Government") {
                array_push($AllCategories, $organization);
            }
        }

        return $AllCategories;
    }

    /**
     * @param $url
     *
     * @return mixed
     */
    private function curl_get(
        $url
    )
    {
        if ('http' != substr($url, 0, 4)) {
            $url = 'http:' . $url;
        }

        try {
            $result = $this->curl_make_request('GET', $url);
        } catch (Exception $ex) {
            echo '<hr />' . $url . '<br />';
            echo $ex->getMessage() . '<hr />';
            $result = false;
        }

        return $result;
    }

//    /**
//     *  Clean trash records, if previous cron script failed
//     */
//    private function cleaner()
//    {
//        $this->wpdb->query("DELETE FROM wp_posts WHERE post_type='metric_new'");
//        $this->wpdb->query("DELETE FROM wp_postmeta WHERE post_id NOT IN (SELECT ID from wp_posts)");
//    }

    /**
     * @param string $method // HTTP method (GET, POST)
     * @param string $uri // URI fragment to CKAN resource
     * @param string $data // Optional. String in JSON-format that will be in request body
     *
     * @return mixed    // If success, either an array or object. Otherwise FALSE.
     * @throws Exception
     */
    private function curl_make_request(
        $method,
        $uri,
        $data = null
    )
    {
        $method = strtoupper($method);
        if (!in_array($method, array('GET', 'POST'))) {
            throw new Exception('Method ' . $method . ' is not supported');
        }
        // Set cURL URI.
        curl_setopt($this->ch, CURLOPT_URL, $uri);
        if ($method === 'POST') {
            if ($data) {
                curl_setopt($this->ch, CURLOPT_POSTFIELDS, urlencode($data));
            } else {
                $method = 'GET';
            }
        }

        // Set cURL method.
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $method);

        // Set headers.
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->ch_headers);
        // Execute request and get response headers.
        $response = curl_exec($this->ch);
        $info = curl_getinfo($this->ch);
        // Check HTTP response code
        if ($info['http_code'] !== 200) {
            switch ($info['http_code']) {
                case 404:
                    throw new Exception($data);
                    break;
                default:
                    throw new Exception(
                        $info['http_code'] . ': ' .
                        $this->http_status_codes[$info['http_code']] . PHP_EOL . $data . PHP_EOL
                    );
            }
        }

        return $response;
    }

    /**
     * @param        $category
     * @param        $title
     * @param        $ckan_id
     * @param        $organizations
     * @param int $parent_node
     * @param int $agency_level
     * @param string $parent_name
     * @param int $sub_agency
     * @param int $export
     *
     * @return mixed
     */
    private function create_metric_content(
        $category,
        $title,
        $ckan_id,
        $organizations,
        $parent_node = 0,
        $agency_level = 0,
        $parent_name = '',
        $sub_agency = 0,
        $export = 0
    )
    {
        if (strlen($ckan_id) != 0) {
            $url = $this->ckanApiUrl . "api/3/action/package_search?fq=($organizations)+AND+dataset_type:dataset&rows=1&sort=metadata_modified+desc";

            $this->stats++;

            $response = $this->curl_get($url);
            $body = json_decode($response, true);

            $count = $body['result']['count'];

            if ($count) {
                $last_entry = $body['result']['results'][0]['metadata_modified'];
//        2013-12-12T07:39:40.341322

                $last_entry = substr($last_entry, 0, 10);
//        2013-12-12

            } else {
                $last_entry = '1970-01-01';
            }
        } else {
            $count = 0;
        }

        $metric_sync_timestamp = time();

        if (!$sub_agency) {
            // && $cfo == 'Y'
            //get list of last 12 months
            $month = date('m');

            $startDate = mktime(0, 0, 0, $month - 11, 1, date('Y'));
            $endDate = mktime(0, 0, 0, $month, date('t'), date('Y'));

            $tmp = date('mY', $endDate);

            $oneYearAgo = date('Y-m-d', $startDate);

            while (true) {
                $months[] = array(
                    'month' => date('m', $startDate),
                    'year' => date('Y', $startDate)
                );

                if ($tmp == date('mY', $startDate)) {
                    break;
                }

                $startDate = mktime(0, 0, 0, date('m', $startDate) + 1, 15, date('Y', $startDate));
            }

            $dataset_count = array();
            $dataset_range = array();

            $i = 1;

            /**
             * Get metrics by current $organizations for each of latest 12 months
             */
            foreach ($months as $date_arr) {
                $startDt = date('Y-m-d', mktime(0, 0, 0, $date_arr['month'], 1, $date_arr['year']));
                $endDt = date('Y-m-t', mktime(0, 0, 0, $date_arr['month'], 1, $date_arr['year']));

                $range = "[" . $startDt . "T00:00:00Z%20TO%20" . $endDt . "T23:59:59Z]";

                $url = $this->ckanApiUrl . "api/3/action/package_search?fq=({$organizations})+AND+dataset_type:dataset+AND+metadata_modified:{$range}&rows=0";
                $this->statsByMonth++;
                $response = $this->curl_get($url);
                $body = json_decode($response, true);

                $dataset_count[$i] = $body['result']['count'];
                $dataset_range[$i] = $range;
                $i++;
            }

            /**
             * Get metrics by current $organizations for latest 12 months TOTAL
             */

            $range = "[" . $oneYearAgo . "T00:00:00Z%20TO%20NOW]";

            $url = $this->ckanApiUrl . "api/3/action/package_search?fq=({$organizations})+AND+dataset_type:dataset+AND+metadata_modified:$range&rows=0";

            $this->statsByMonth++;
            $response = $this->curl_get($url);
            $body = json_decode($response, true);

            $lastYearCount = $body['result']['count'];
            $lastYearRange = $range;
        }

//        create a new agency in DB, if not found yet
        $my_post = array(
            'post_title' => $title,
            'post_status' => 'publish',
            'post_type' => 'metric_new'
        );

        $content_id = wp_insert_post($my_post);

        list($Y, $m, $d) = explode('-', $last_entry);
        $last_entry = "$m/$d/$Y";

        $this->update_post_meta($content_id, 'metric_count', $count);

        if (!$sub_agency) {
            // && $cfo == 'Y'
            for ($i = 1; $i < 13; $i++) {
                $this->update_post_meta($content_id, 'month_' . $i . '_dataset_count', $dataset_count[$i]);
            }

            $this->update_post_meta($content_id, 'last_year_dataset_count', $lastYearCount);

            for ($i = 1; $i < 13; $i++) {
                $this->update_post_meta(
                    $content_id,
                    'month_' . $i . '_dataset_url',
                    $this->ckanUrl . 'dataset?q=(' . $organizations . ')+AND+dataset_type:dataset+AND+metadata_modified:' . $dataset_range[$i]
                );
            }

            $this->update_post_meta(
                $content_id,
                'last_year_dataset_url',
                $this->ckanUrl . 'dataset?q=(' . $organizations . ')+AND+dataset_type:dataset+AND+metadata_modified:' . $lastYearRange
            );

        }

        if ($category == "Cooperative ") {
            $this->update_post_meta($content_id, 'metric_sector', 'Cooperative');
        } elseif ($category == "Other") {
            $this->update_post_meta($content_id, 'metric_sector', 'Other Non-Federal');
        } else {
            $this->update_post_meta($content_id, 'metric_sector', $category);
        }

        $this->update_post_meta($content_id, 'ckan_unique_id', $ckan_id);
        $this->update_post_meta($content_id, 'metric_last_entry', $last_entry);
        $this->update_post_meta($content_id, 'metric_sync_timestamp', $metric_sync_timestamp);

        $this->update_post_meta(
            $content_id,
            'metric_url',
            $this->ckanUrl . 'dataset?q=' . $organizations
        );

        if (!$sub_agency) {
            $this->update_post_meta($content_id, 'is_root_organization', 1);
            $this->counts[trim($title)] = $count;
        } else {
            $this->update_post_meta($content_id, 'is_sub_organization', 1);
        }

        if ($parent_node != 0) {
            $this->update_post_meta($content_id, 'parent_organization', $parent_node);
        }

        if ($agency_level != 0) {
            $this->update_post_meta($content_id, 'parent_agency', 1);
        }

        $flag = false;
        if ($count > 0) {
            if ($export != 0) {
                $this->results[] = array($parent_name, $title, $category, $count, $last_entry);
            }

            if ($parent_node == 0 && $flag == false) {
                $parent_name = $title;
                $title = '';

                $this->results[] = array($parent_name, $title, $category, $count, $last_entry);
            }
        }

        return $content_id;
    }

    /**
     * Temporary to remove all duplicate meta
     * Removes ONLY with manual launch
     *
     * @param $post_id
     * @param $meta_key
     * @param $meta_value
     */
    private function update_post_meta($post_id, $meta_key, $meta_value)
    {
//        if (defined('DELETE_DUPLICATE_META') && DELETE_DUPLICATE_META) {
//            delete_post_meta($post_id, $meta_key);
//        }
        update_post_meta($post_id, $meta_key, $meta_value);
    }

    /**
     * @param MetricsTaxonomy $RootOrganization
     * @param                 $parent_nid
     */
    private function create_metric_content_department_level_without_publisher($RootOrganization, $parent_nid)
    {
        $publisherTitle = '    Department/Agency level/No publisher';

        // https://catalog.data.gov/api/3/action/package_search?q=organization:(gsa-gov)+AND+type:dataset+AND+-extras_publisher:*&sort=metadata_modified+desc&rows=1
        $ckan_organization = 'organization:' . urlencode(
                $RootOrganization['name']
            ) . '+AND+type:dataset+AND+-extras_publisher:*';
        $url = $this->ckanApiUrl . "api/3/action/package_search?q={$ckan_organization}&sort=metadata_modified+desc&rows=1";

        $this->stats++;

        $response = $this->curl_get($url);
        $body = json_decode($response, true);

        if (!isset($body['result']['count']) || !($count = $body['result']['count'])) {
            return;
        }

//        skip if it would be the one sub-agency
        if ($count == $this->counts[trim($RootOrganization['title'])]) {
            return;
        }

        $my_post = array(
            'post_title' => $publisherTitle,
            'post_status' => 'publish',
            'post_type' => 'metric_new'
        );

        $content_id = wp_insert_post($my_post);

        $this->update_post_meta($content_id, 'metric_department_lvl', $parent_nid);

        $this->update_post_meta($content_id, 'metric_count', $count);

//            http://catalog.data.gov/dataset?publisher=United+States+Mint.+Sales+and+Marketing+%28SAM%29+Department
        $this->update_post_meta(
            $content_id,
            'metric_url',
            $this->ckanUrl . "dataset?q={$ckan_organization}"
        );

        $category = $RootOrganization['organization_type'];
        if ($category == "Cooperative ") {
            $this->update_post_meta($content_id, 'metric_sector', 'Cooperative');
        } elseif ($category == "Other") {
            $this->update_post_meta($content_id, 'metric_sector', 'Other Non-Federal');
        } else {
            $this->update_post_meta($content_id, 'metric_sector', $category);
        }

        $this->update_post_meta($content_id, 'parent_organization', $parent_nid);

        $last_entry = '-';
        if (isset($body['result']) && isset($body['result']['results'])) {
            $last_entry = $body['result']['results'][0]['metadata_modified'];
            $last_entry = substr($last_entry, 0, 10);

            list($Y, $m, $d) = explode('-', $last_entry);
            $last_entry = "$m/$d/$Y";

            $this->update_post_meta($content_id, 'metric_last_entry', $last_entry);
        }

        $this->results[] = array($RootOrganization['title'], trim($publisherTitle), $category, $count, $last_entry);
    }

    /**
     * @param MetricsTaxonomy $RootOrganization
     * @param                   $parent_nid
     *
     * @return int
     */
    private function create_metric_content_by_publishers($RootOrganization, $parent_nid)
    {
//        http://catalog.data.gov/api/action/package_search?q=organization:treasury-gov+AND+type:dataset&rows=0&facet.field=publisher
        $ckan_organization = 'organization:' . urlencode($RootOrganization['name']) . '+AND+type:dataset';
        $url = $this->ckanApiUrl . "api/3/action/package_search?q={$ckan_organization}&rows=0&facet.field=[%22publisher%22]&facet.limit=200";
        $this->stats++;

        $response = $this->curl_get($url);
        $body = json_decode($response, true);

        if (!isset($body['result']['facets']['publisher'])) {
            return;
        }

        $publishers = $body['result']['facets']['publisher'];
        if (!sizeof($publishers)) {
            return;
        }

        ksort($publishers);

        foreach ($publishers as $publisherTitle => $count) {
            $my_post = array(
                'post_title' => $publisherTitle,
                'post_status' => 'publish',
                'post_type' => 'metric_new'
            );

            $content_id = wp_insert_post($my_post);

            $this->update_post_meta($content_id, 'metric_publisher', $parent_nid);

            $this->update_post_meta($content_id, 'metric_count', $count);

//            http://catalog.data.gov/dataset?publisher=United+States+Mint.+Sales+and+Marketing+%28SAM%29+Department
            $this->update_post_meta(
                $content_id,
                'metric_url',
                $this->ckanUrl . "dataset?q={$ckan_organization}&publisher=" . urlencode($publisherTitle)
            );

            $category = $RootOrganization['organization_type'];

            if ($category == "Cooperative ") {
                $this->update_post_meta($content_id, 'metric_sector', 'Cooperative');
            } elseif ($category == "Other") {
                $this->update_post_meta($content_id, 'metric_sector', 'Other Non-Federal');
            } else {
                $this->update_post_meta($content_id, 'metric_sector', $category);
            }

            $this->update_post_meta($content_id, 'parent_organization', $parent_nid);

//                http://catalog.data.gov/api/action/package_search?q=type:dataset+AND+extras_publisher:United+States+Mint.+Sales+and+Marketing+%28SAM%29+Department&sort=metadata_modified+desc&rows=1

            $apiPublisherTitle = str_replace(array('/', '%2F'), array('\/', '%5C%2F'), urlencode($publisherTitle));
            $url = $this->ckanApiUrl . "api/action/package_search?q={$ckan_organization}+AND+extras_publisher:" . $apiPublisherTitle . "&sort=metadata_modified+desc&rows=1";

            $this->stats++;

            $response = $this->curl_get($url);
            $body = json_decode($response, true);

            $last_entry = '-';
            if (isset($body['result']) && isset($body['result']['results'])) {
                $last_entry = $body['result']['results'][0]['metadata_modified'];
                $last_entry = substr($last_entry, 0, 10);

                list($Y, $m, $d) = explode('-', $last_entry);
                $last_entry = "$m/$d/$Y";

                $this->update_post_meta($content_id, 'metric_last_entry', $last_entry);
            }

            $this->results[] = array($RootOrganization['title'], trim($publisherTitle), $category, $count, $last_entry);
        }

        return;
    }


    private function write_metrics_csv_and_xls()
    {
        asort($this->results);

        $upload_dir = wp_upload_dir();

        $csvFilenameNonFed = 'non-federal-agency-participation.csv';
        $csvFilenameFed = 'federal-agency-participation.csv';
        $csvAgencyParticipation = 'agency-participation.csv';
        $csvPath = $upload_dir['basedir'] . '/' . $csvFilenameNonFed;
        $csvPathFed = $upload_dir['basedir'] . '/' . $csvFilenameFed;
        $csvPathAgencyParticipation = $upload_dir['basedir'] . '/' . $csvAgencyParticipation;

        @chmod($csvPath, 0666);
        if (file_exists($csvPath) && !is_writable($csvPath)) {
            die('could not write ' . $csvPath);
        }

//    Write CSV result file
        $fp_csv = fopen($csvPath, 'w');

        if ($fp_csv == false) {
            die("unable to create file");
        }

        fputcsv($fp_csv, array('Agency Name', 'Sub-Agency/Publisher', 'Organization Type', 'Datasets', 'Last Entry'));

        foreach ($this->results as $record) {
            fputcsv($fp_csv, $record);
        }
        fclose($fp_csv);


        @chmod($csvPath, 0666);

        if (!file_exists($csvPath)) {
            die('could not write ' . $csvPath);
        }

        $this->upload_to_s3($csvPath, $csvFilenameNonFed);
        // This function combines two csv files. Fed and Nonfed Agency Participation csv
        function joinFiles(array $files, $result)
        {
            if (!is_array($files)) {
                throw new Exception('`$files` must be an array');
            }
            $notFirstFile = false;
            $wH = fopen($result, "w+");
            foreach ($files as $file) {
                $firstLine = true;
                $fh = fopen($file, "r");
                while (!feof($fh)) {
                    if ($notFirstFile == true && $firstLine == true) {
                        $firstLine = false;
                        fgets($fh);
                    } else {
                        fwrite($wH, fgets($fh));
                    }
                }
                fclose($fh);
                unset($fh);
                $notFirstFile = true;
            }
            fclose($wH);
            unset($wH);
        }

        joinFiles(array($csvPathFed, $csvPath), $csvPathAgencyParticipation);

        $this->upload_to_s3($csvPathAgencyParticipation, $csvAgencyParticipation);

        // Instantiate a new PHPExcel object
        $objPHPExcel = new PHPExcel();
        // Set the active Excel worksheet to sheet 0
        $objPHPExcel->setActiveSheetIndex(0);
        // Initialise the Excel row number
        $row = 1;

        $objPHPExcel->getActiveSheet()->SetCellValue('A' . $row, 'Agency Name');
        $objPHPExcel->getActiveSheet()->SetCellValue('B' . $row, 'Sub-Agency/Publisher');
        $objPHPExcel->getActiveSheet()->SetCellValue('C' . $row, 'Organization Type');
        $objPHPExcel->getActiveSheet()->SetCellValue('D' . $row, 'Datasets');
        $objPHPExcel->getActiveSheet()->SetCellValue('E' . $row, 'Last Entry');
        $row++;

        foreach ($this->results as $record) {
            if ($record) {
                $objPHPExcel->getActiveSheet()->SetCellValue('A' . $row, trim($record[0]));
                $objPHPExcel->getActiveSheet()->SetCellValue('B' . $row, trim($record[1]));
                $objPHPExcel->getActiveSheet()->SetCellValue('C' . $row, trim($record[2]));
                $objPHPExcel->getActiveSheet()->SetCellValue('D' . $row, $record[3]);
                $objPHPExcel->getActiveSheet()->SetCellValue('E' . $row, $record[4]);
                $row++;
            }
        }

        $objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');

        $xlsFilename = 'non-federal-agency-participation.xlsx';
        $xlsPath = $upload_dir['basedir'] . '/' . $xlsFilename;
        @chmod($xlsPath, 0666);
        if (file_exists($xlsPath) && !is_writable($xlsPath)) {
            die('could not write ' . $xlsPath);
        }

        $objWriter->save($xlsPath);
        @chmod($xlsPath, 0666);

        if (!file_exists($xlsPath)) {
            die('could not write ' . $xlsPath);
        }

        $this->upload_to_s3($xlsPath, $xlsFilename);

        // This here combines the Fed/NonFed xls into one file
        $xlsFed = 'federal-agency-participation.xlsx';
        $xlsFedPath = $upload_dir['basedir'] . '/' . $xlsFed;
        $xlsNonFed = $xlsFilename;
        $xlsNonFedPath = $xlsPath;
        $xlsAgencyParticipation = 'agency-participation.xlsx';
        $xlsAgencyParticipationPath = $upload_dir['basedir'] . '/' . $xlsAgencyParticipation;

        $objPHPExcelFed = PHPExcel_IOFactory::load($xlsFedPath);
        $objPHPExcelNonFed = PHPExcel_IOFactory::load($xlsNonFedPath);
        $objPHPExcelFed->setActiveSheetIndex(0);
        $objPHPExcelNonFed->setActiveSheetIndex(0);
        // Find the last cell in the second spreadsheet
        $findEndDataRow = $objPHPExcelNonFed->getActiveSheet()->getHighestRow();
        $findEndDataColumn = $objPHPExcelNonFed->getActiveSheet()->getHighestColumn();
        $findEndData = $findEndDataColumn . $findEndDataRow;
        // Read all the data from second spreadsheet to a normal PHP array skipping the headers in row 1
        $beeData = $objPHPExcelNonFed->getActiveSheet()->rangeToArray('A2:' . $findEndData);
        // Identify the row in the first spreadsheet where we want to start adding merged bee data without overwriting any bird data
        $appendStartRow = $objPHPExcelFed->getActiveSheet()->getHighestRow() + 1;
        // Add bee data from the PHP array into the bird data
        $objPHPExcelFed->getActiveSheet()->fromArray($beeData, null, 'A' . $appendStartRow);
        $objWriter = PHPExcel_IOFactory::createWriter($objPHPExcelFed, "Excel2007");
        $objWriter->save($xlsAgencyParticipationPath);

        $this->upload_to_s3($xlsAgencyParticipationPath, $xlsAgencyParticipation);
    }

    /**
     * @param $from_local_path
     * @param $to_s3_path
     * @param string $acl
     */
    private function upload_to_s3($from_local_path, $to_s3_path, $acl = 'public-read')
    {
        if (WP_ENV !== 'production') {
            return;
        }
        // Create a service locator using a configuration file
        $aws = Aws::factory(array(
            'region' => 'us-east-1'
        ));

        // Get client instances from the service locator by name
        $s3 = $aws->get('s3');

        $s3_config = get_option('tantan_wordpress_s3');
        if (!$s3_config) {
            echo 's3 plugin is not configured';
            return;
        }

        $s3_bucket = $s3_config['bucket'];
        $s3_prefix = $s3_config['object-prefix'];

//        avoiding tailing double-slash
        $s3_prefix = rtrim($s3_prefix, '/') . '/';

//        avoiding prefix slash
        $to_s3_path = ltrim($to_s3_path, '/');

        // Upload a publicly accessible file. The file size and type are determined by the SDK.
        try {
            $s3->putObject([
                'Bucket' => $s3_bucket,
                'Key' => $s3_prefix . $to_s3_path,
                'Body' => fopen($from_local_path, 'r'),
                'ACL' => $acl,
            ]);
        } catch (Aws\Exception\S3Exception $e) {
            echo "There was an error uploading the file.\n";
            return;
        }
    }

    /**
     *  Replace previous data with latest metrics
     */
    private function publishNewMetrics()
    {
        $this->wpdb->query("DELETE FROM wp_posts WHERE post_type='metric_organization'");
        $this->wpdb->query(
            "UPDATE wp_posts SET post_type='metric_organization' WHERE post_type='metric_new'"
        );
        $this->wpdb->query("DELETE FROM wp_postmeta WHERE post_id NOT IN (SELECT ID FROM wp_posts)");

        update_option('metrics_updated_gmt', gmdate("m/d/Y h:i A", time()) . ' GMT');
    }

    /**
     *  Unlock the system for next cron run
     */
    private function unlock()
    {
        delete_option(self::LOCK_TITLE);
    }

    /**
     * @return bool
     * unlocked automatically after 30 minutes, if script died
     */
    private function checkLock()
    {
        $lock = get_option(self::LOCK_TITLE);

        if ($lock) {
            $now = time();
            $diff = $now - $lock;

//            30 minutes lock
            if ($diff < (30 * 60)) {
                return false;
            }
        }

        $this->lock();

        return true;
    }

    /**
     *  Lock the system to avoid simultaneous cron runs
     */
    private function lock()
    {
        update_option(self::LOCK_TITLE, time());
    }
}
