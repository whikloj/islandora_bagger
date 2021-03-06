<?php
// src/Service/IslandoraBagger.php
namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// We need the first set_include_path() for the console command, and the second
// for the REST API.
set_include_path(get_include_path() . PATH_SEPARATOR . 'vendor/scholarslab/bagit/lib/');
set_include_path(get_include_path() . PATH_SEPARATOR . '../vendor/scholarslab/bagit/lib/');
require 'bagit.php';

class IslandoraBagger
{
    public function __construct($settings, $logger, $params)
    {
        $this->params = $params;

        $this->settings = $settings;
        $this->logger = $logger;

        $this->application_directory = dirname(__DIR__, 2);
    }

    /**
     * Create a Bag for the current node.
     *
     * @param string $nid
     *   The node ID.
     * @param string $settings_path
     *   The path to the settings YAML file passed in from the Create Bag command.
     *
     * @return string|bool
     *   The path to the Bag if successful, false if unsuccessful.
     *   If Bag is serialized, path includes path and Bag filename.
     */
    public function createBag($nid, $settings_path)
    {
        // Set some configuration defaults.
        $this->settings['http_timeout'] = (!isset($this->settings['http_timeout'])) ?
            60 : $this->settings['http_timeout'];
        $this->settings['verify_ca'] = (!isset($this->settings['verify_ca'])) ?
            true : $this->settings['verify_ca'];
        $this->settings['hash_algorithm'] = (!isset($this->settings['hash_algorithm'])) ?
            'sha1' : $this->settings['hash_algorithm'];
        $this->settings['include_payload_oxum'] = (!isset($this->settings['include_payload_oxum'])) ?
            true : $this->settings['include_payload_oxum'];
        $this->settings['delete_settings_file'] = (!isset($this->settings['delete_settings_file'])) ?
            false : $this->settings['delete_settings_file'];
        $this->settings['log_bag_location'] = (!isset($this->settings['log_bag_location'])) ?
            false : $this->settings['log_bag_location'];

        if (!file_exists($this->settings['output_dir'])) {
            mkdir($this->settings['output_dir']);
        }
        if (!file_exists($this->settings['temp_dir'])) {
            mkdir($this->settings['temp_dir']);
        }

        $client = new \GuzzleHttp\Client();

        // Get the node's UUID from Drupal.
        $drupal_url = $this->settings['drupal_base_url'] . '/node/' . $nid . '?_format=json';
        $response = $client->get($drupal_url);
        $response_body = (string) $response->getBody();
        $node_json = $response_body;
        $body_array = json_decode($response_body, true);
        $uuid = $body_array['uuid'][0]['value'];

        if ($this->settings['bag_name'] == 'uuid') {
            $bag_name = $uuid;
        } else {
            $bag_name = $nid;
        }

        // Create directories.
        $bag_dir = $this->settings['output_dir'] . DIRECTORY_SEPARATOR . $bag_name;
        if (file_exists($bag_dir)) {
            $this->removeDir($bag_dir);
        }
        mkdir($bag_dir);

        $bag_temp_dir = $this->settings['temp_dir'] . DIRECTORY_SEPARATOR . $bag_name;
        if (file_exists($bag_temp_dir)) {
            $this->removeDir($bag_temp_dir);
        }
        mkdir($bag_temp_dir);

        // Create the Bag.
        $bag_info = array();
        $bag = new \BagIt($bag_dir, true, true, true, $bag_info);
        $bag->setHashEncoding($this->settings['hash_algorithm']);

        // Add tags registered in the config file.
        foreach ($this->settings['bag-info'] as $key => $value) {
            $bag->setBagInfoData($key, $value);
        }

        // Execute registered plugins.
        foreach ($this->settings['plugins'] as $plugin) {
            $plugin_name = 'App\Plugin\\' . $plugin;
            $bag_plugin = new $plugin_name($this->settings, $this->logger);
            $bag = $bag_plugin->execute($bag, $bag_temp_dir, $nid, $node_json);
        }

        if ($this->settings['include_payload_oxum']) {
            $bag->setBagInfoData('Payload-Oxum', $this->generateOctetstreamSum($bag));
        }

        $bag->update();
        $this->removeDir($bag_temp_dir);

        $package = isset($this->settings['serialize']) ? $this->settings['serialize'] : false;
        if ($package) {
            $bag_file_path = $this->settings['output_dir'] . DIRECTORY_SEPARATOR . $bag_name  . '.' . $package;
            if (file_exists($bag_file_path)) {
                @unlink($bag_file_path);
            }
            $bag->package($bag_dir, $package);
            $this->removeDir($bag_dir);
            if ($this->settings['log_bag_location']) {
                $this->logBagLocation($nid, $bag_name . '.' . $package);
            }
        }

        if ($this->settings['log_bag_creation']) {
            $this->logger->info(
                "Bag created.",
                array(
                    'node URL' => $this->settings['drupal_base_url'] . '/node/' . $nid,
                    'node UUID' => $uuid,
                    'Bag location' => $this->settings['output_dir'],
                    'Bag name' => $bag_name
                )
            );
        }

        if ($this->settings['delete_settings_file']) {
            unlink(realpath($settings_path));
        }

        // @todo: Return Bag directory path on success or false failure.
        if ($package) {
            return $bag_file_path;
        } else {
            return $bag_dir;
        }
    }

    /**
     * @param object $bag
     *  The Bag object.
     *
     * @return string
     *   The Payload-Oxum value.
     */
    protected function generateOctetstreamSum($bag)
    {
        $file_counter = 0;
        $filesize_sum = 0;
        foreach ($bag->getBagContents() as $file_path) {
             $file_counter++;
             $filesize_sum = filesize($file_path) + $filesize_sum;
        }
        return $filesize_sum . '.' . $file_counter;
    }

    /**
     * @param string $nid
     *  The node ID of the Islandora object.
     *
     * @return bool
     *   Whether or not the location was logged.
     */
    protected function logBagLocation($nid, $bag_name)
    {
        $location_log_path = $this->params->get('app.location.log.path');
        $now_iso8601 = date(\DateTime::ISO8601);
        $bag_location = $this->params->get('app.bag.download.prefix') . $bag_name;
        $data = $nid . "\t" . $bag_location . "\t" . $now_iso8601 . PHP_EOL;
        if (file_put_contents($location_log_path, $data, FILE_APPEND)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Deletes a directory and all of its contents.
     *
     * @param $dir string
     *   Path to the directory.
     *
     * @return bool
     *   True if the directory was deleted, false if not.
     */
    protected function removeDir($dir)
    {
        $invalid_dirs = array('/', '/tmp');
        if (in_array($dir, $invalid_dirs)) {
            $this->logger->warning(
                "Directory is in list of directories to not remove.",
                array('Directory' => $dir)
            );
            return false;
        }
        $files = array_diff(scandir($dir), array('.','..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->removeDir("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }
}
