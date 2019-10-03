<?php

/**
 * Wizard for importing items into an existing collection.
 * @package Wizard
 */
class BatchUpload_Wizard_ExistingCollection extends BatchUpload_Application_AbstractWizard
{
    // Special property codes
    const SPECIAL_TYPE_UNMAPPED = 0;
    const SPECIAL_TYPE_TAGS = -1;
    const SPECIAL_TYPE_FILE = -2;
    const SPECIAL_TYPE_ITEMTYPE = -3;
    const SPECIAL_TYPE_COLLECTION = -4;
    const SPECIAL_TYPE_PUBLIC = -5;
    const SPECIAL_TYPE_FEATURED = -6;

    // Identify the name slug of the job type and number of steps
    public $job_type = "existing_collection";
    public $job_type_description = "Existing Collection";
    public $steps = 4; // Select target collection, specify metadata, create rows, upload files

    /**
     * Hook for what to do when a new job is created.
     * Set the target type to collection.
     *
     * @param BatchUpload_Job $job
     */
    public function newJob($job)
    {
        // Initialize new job target type to Collection
        $job->target_type = "Collection";
    }

    /**
     * Filter for changing the value
     * @param array $row
     * @return array
     */
    public function jobRow($row)
    {
        $row = parent::jobRow($row);
        $job = $row['job'];
        if (!empty($job->target_id) && $collection = get_record_by_id($job->target_type, $job->target_id))
        {
            $row['target'] = link_to_collection(null, array(), 'show', $collection);
        }
        return $row;
    }

    /**
     * Rendering step 1's form for selecting the target collection.
     * @param array $args
     */
    public function step1Form($args)
    {
        $job = $args['job'];
        $partialAssigns = $args['partial_assigns'];
        $form = new BatchUpload_Form_CollectionSelect();
        $form->getElement('target_id')->setValue($job->target_id);
        $partialAssigns->set('form', $form);
        $partialAssigns->set('page_title', __("Select Target"));
    }

    /**
     * Processing step 1's form for selecting the target collection.
     * @param array $args
     */
    public function step1Process($args)
    {
        $job = $args['job'];
        $partialAssigns = $args['partial_assigns'];
        $form = new BatchUpload_Form_CollectionSelect();
        // If valid, record the target ID, go to the next step and save
        if ($this->validateAndCarryForm($form))
        {
            $job->target_id = $form->getElement('target_id')->getValue();
            $job->step++;
            $job->save();
        }
        // If invalid, go back to the form
        else
        {
            $partialAssigns->set('form', $form);
            $partialAssigns->set('page_title', __("Select Target"));
        }
    }

    /**
     * Rendering step 2's form for mappings.
     * @param array $args
     */
    public function step2Form($args)
    {
        $partialAssigns = $args['partial_assigns'];
        // Embed JS files
        queue_js_file('papaparse.min', 'lib/papaparse');
        queue_js_file('CsvImportMappings', 'js');
        // Set page title
        $partialAssigns->set('page_title', __("Input Data"));
        // These two partial variables drive the mappings screen.
        // available_properties: Preprogrammed mappings from name to property ID. { header: id, header: id, ... }
        // available_properties_options: HTML string consisting of options to place inside the property select dropdown.
        $availablePropertiesArray = $this->__getAvailablePropertiesArray();
        $partialAssigns->set('available_properties', $this->__getAvailablePropertiesJson($availablePropertiesArray));
        $partialAssigns->set('available_properties_options', $this->__getAvailablePropertiesOptions($availablePropertiesArray));
        $partialAssigns->set('mapping_sets', get_db()->getTable('BatchUpload_MappingSet')->findAll());
    }

    /**
     * Process step 2's submissions.
     * @param array $args
     */
    public function step2Process($args)
    {
        $job = $args['job'];
        $post = $args['post'];
        $valid = true;
        $csvData = array();
        // Must have "metadata" mapping to be valid
        if (empty($post['metadata']))
        {
            $valid = false;
        }
        // Must have "csv_data" and it should decode properly
        if (empty($post['csv_data']))
        {
            $valid = false;
        }
        else
        {
            $csvData = json_decode($post['csv_data'], true);
            if (empty($csvData) || count($csvData[0]) != count($post['metadata']))
            {
                $valid = false;
            }
        }
        // Proceed if valid
        if ($valid)
        {
            // Go to step 3
            $job->step++;
            $job->setJsonData(array(
                'csvData' => $csvData,
                'metadata' => $post['metadata'],
            ));
            $job->save();
            // Start background running job
            Zend_Registry::get('bootstrap')->getResource('jobs')->sendLongRunning('BatchUpload_Job_GenerateRows', array(
                'jobId' => $job->id,
                'hasHeaders' => isset($post['has_headers']),
            ));
        }
        // Otherwise, re-render the form
        else
        {
            $this->step2Form($args);
        }
    }

    /**
     * Process step 2's potential AJAX calls
     * @param array $args
     */
    public function step2Ajax($args)
    {
        $post = $args['post'];
        $response = $args['response'];
        $http = $args['http'];
        // Multiplex via "action" key
        if (!empty($post['action']))
        {
            switch ($post['action'])
            {
                // Save mapping
                // Accepts { action: "save-mapping", set_name: "...", mappings: [{header:"...", order:o, property:"...", html:true/false}, ...] }
                // Responds { success: true/false, mapping_set: id }
                case 'save-mapping':
                    try {
                        $newMappingSet = new BatchUpload_MappingSet();
                        $newMappingSet->name = $post['set_name'];
                        $newMappingSet->save();
                        foreach ($post['mappings'] as $i => $mappingData)
                        {
                            $newMapping = new BatchUpload_Mapping();
                            $newMapping->mapping_set_id = $newMappingSet->id;
                            $newMapping->header = $mappingData['header'];
                            $newMapping->order = $i+1;
                            $newMapping->property = $mappingData['property'];
                            $newMapping->html = $mappingData['html'];
                            $newMapping->save();
                        }
                        $response->set('mapping_set', $newMappingSet->id);
                        $response->set('message', __('Successfully added mapping template "%s"!', $post['set_name']));
                    } catch (Exception $ex) {
                        $response->set('success', false);
                        $response->set('message', $ex->getMessage());
                        $http->set('status', 422);
                    }
                    break;
                // Apply mapping
                // Accepts { action: "apply-mapping", mapping_set: id }
                // Responds { success: true/false, mappings: [{header:"...", order:o, property:"...", html:true/false}, ...] }
                case 'apply-mapping':
                    try {
                        $mappingsData = array();
                        $mappingSet = get_db()->getTable('BatchUpload_MappingSet')->find($post['mapping_set']);
                        foreach ($mappingSet->getMappings() as $mapping)
                        {
                            $mappingsData[] = array(
                                'header' => $mapping->header,
                                'order' => $mapping->order,
                                'property' => $mapping->property,
                                'html' => $mapping->html,
                            );
                        }
                        $response->set('mappings', $mappingsData);
                    } catch (Exception $ex) {
                        $response->set('success', false);
                        $response->set('message', $ex->getMessage());
                    }
                    break;
            }
        }
    }

    /**
     * Return a utility array in the form { "Category": {"property_id": "Property Name"}, ... }
     * @return array
     */
    private function __getAvailablePropertiesArray()
    {
        $properties = array(
            __("Special Properties") => array(
                self::SPECIAL_TYPE_UNMAPPED => __("[Unmapped]"),
                self::SPECIAL_TYPE_TAGS => __("Tags"),
                self::SPECIAL_TYPE_FILE => __("File"),
                self::SPECIAL_TYPE_ITEMTYPE => __("Item Type"),
                self::SPECIAL_TYPE_COLLECTION => __("Collection"),
                self::SPECIAL_TYPE_PUBLIC => __("Public"),
                self::SPECIAL_TYPE_FEATURED => __("Featured"),
            )
        );
        $elementSets = get_db()->getTable('ElementSet')->findAll();
        foreach ($elementSets as $elementSet)
        {
            $idNamePairs = array();
            $elementTexts = $elementSet->getElements();
            foreach ($elementTexts as $elementText)
            {
                $idNamePairs[$elementText->id] = $elementText->name;
            }
            $properties[$elementSet->name] = $idNamePairs;
        }
        return $properties;
    }

    /**
     * Return an associative array for automatic mapping, in the form { "label": "property_id", ... }.
     * @param array|null $availableProperties Utility array in same form returned by __getAvailablePropertiesArray().
     * @return array
     */
    private function __getAvailablePropertiesJson($availableProperties=null)
    {
        if ($availableProperties === null)
        {
            $availableProperties = $this->__getAvailablePropertiesArray();
        }
        $json = array();
        // Enumerate default SET:ELEMENT combinations
        foreach ($availableProperties as $elementSetName => $elements)
        {
            foreach ($elements as $elementId => $elementName)
            {
                $json["{$elementSetName}:{$elementName}"] = $elementId;
                $json[$elementName] = $elementId;
            }
        }
        // Add a few specials
        $json['tags'] = self::SPECIAL_TYPE_TAGS;
        $json['file'] = self::SPECIAL_TYPE_FILE;
        $json['itemType'] = self::SPECIAL_TYPE_ITEMTYPE;
        $json['collection'] = self::SPECIAL_TYPE_COLLECTION;
        $json['public'] = self::SPECIAL_TYPE_PUBLIC;
        $json['featured'] = self::SPECIAL_TYPE_FEATURED;
        // Done
        return $json;
    }

    /**
     * Return a string in HTML that can be inserted between SELECT tags to choose a property.
     * @param array|null $availableProperties Utility array in same form returned by __getAvailablePropertiesArray().
     * @return string
     */
    private function __getAvailablePropertiesOptions($availableProperties=null)
    {
        if ($availableProperties === null)
        {
            $availableProperties = $this->__getAvailablePropertiesArray();
        }
        $selectContent = '';
        foreach ($availableProperties as $elementSetName => $elements)
        {
            $selectContent .= '<optgroup label="' . html_escape($elementSetName) . '">';
            foreach ($elements as $elementId => $elementName)
            {
                $selectContent .= '<option value="' . $elementId . '">' . html_escape($elementName) . '</option>';
            }
            $selectContent .= '</optgroup>';
        }
        return $selectContent;
    }

    /**
     * Display the waiting screen for step 3's row generation.
     * @param array $args
     */
    public function step3Form($args)
    {
        queue_js_file('MonitorStepStatus', 'js');
        $job = $args['job'];
        $partialAssigns = $args['partial_assigns'];
        $partialAssigns->set('page_title', __("Processing Data Rows"));
        $partialAssigns->set('status_url', admin_url(array('controller' => 'jobs', 'id' => $job->id, 'action' => 'lookup'), 'batchupload_id'));
        $partialAssigns->set('refresh_url', admin_url(array('controller' => 'jobs', 'id' => $job->id, 'action' => 'refresh'), 'batchupload_id'));
        $partialAssigns->set('current_step', $job->step);
    }

    /**
     * Don't do anything for step 3's processing, a background job is doing it.
     * @param array $args
     */
    public function step3Process($args)
    {
        $this->step3Form($args);
    }

    /**
     * Display the file upload screen for step 4.
     * @param array $args
     */
    public function step4Form($args)
    {
        $job = $args['job'];
        $partialAssigns = $args['partial_assigns'];
        queue_js_file('jquery.ui.widget', 'lib/jquery-file-upload/js/vendor');
        queue_js_file('load-image.all.min', 'lib/jquery-file-upload/js/vendor');
        queue_js_file('canvas-to-blob.min', 'lib/jquery-file-upload/js/vendor');
        queue_js_file('jquery.iframe-transport', 'lib/jquery-file-upload/js');
        queue_js_file('jquery.fileupload', 'lib/jquery-file-upload/js');
        queue_js_file('jquery.fileupload-process', 'lib/jquery-file-upload/js');
        queue_js_file('jquery.fileupload-image', 'lib/jquery-file-upload/js');
        queue_js_file('jquery.fileupload-audio', 'lib/jquery-file-upload/js');
        queue_js_file('jquery.fileupload-video', 'lib/jquery-file-upload/js');
        queue_js_file('jquery.fileupload-validate', 'lib/jquery-file-upload/js');
        // Collect rows
        $fileRows = array();
        foreach ($job->getUploadRows() as $row)
        {
            $fileRows[] = $row->getJsonData();
        }
        $partialAssigns->set('page_title', __("Supply Files"));
        $partialAssigns->set('file_rows', $fileRows);
        $partialAssigns->set('processing_path', admin_url(array('id' => $job->id, 'controller' => 'jobs', 'action' => 'ajax'), 'batchupload_id'));
    }

    /**
     * Don't do anything for step 4's processing, AJAX is doing it.
     * @param array $args
     */
    public function step4Process($args)
    {
        $this->step4Form($args);
    }

    /**
     * Process AJAX uploads for step 4.
     * @param array $args
     */
    public function step4Ajax($args)
    {
        $job = $args['job'];
        $files = $args['files'];
        $response = $args['response'];
        // Find the row for the given files
        $affectedRows = get_db()->getTable('BatchUpload_Row')->findBySql("job_id = ? AND data LIKE CONCAT('%', ?, '%')", array($job->id, '"file":' . json_encode($files['files']['name'][0])));
        if (empty($affectedRows))
        {
            $affectedRows = array();
        }
        // Inserted and failed files
        $insertedFileRecords = array();
        $failedFiles = array();
        // If there are no affected rows, this file is not expected
        if (empty($affectedRows))
        {
            $failedFiles[] = array(
                'name' => $files['files']['name'][0],
                'reason' => __("File not expected"),
            );
        }
        // Otherwise, the file is expected and we attempt to add it to all items that expect it
        else
        {
            foreach ($affectedRows as $row)
            {
                $rowData = $row->getJsonData();
                try {
                    $oldErrorReporting = error_reporting();
                    error_reporting($oldErrorReporting & E_ERROR);
                    $insertedFiles = insert_files_for_item($rowData['item'], 'Upload', 'files', array(
                        'ignore_invalid_files' => true,
                        'ignoreNoFile' => true,
                    ));
                    error_reporting($oldErrorReporting);
                    $insertedFileRecords = array_merge($insertedFileRecords, $insertedFiles);
                } catch (Exception $ex) {
                    $failedFiles[] = array(
                        'name' => $files['files']['name'][0],
                        'reason' => __($ex->getMessage()),
                    );
                }
                $row->delete();
            }
        }
        // Generate response
        $insertedFileEntries = array();
        foreach ($insertedFileRecords as $insertedFileRecord)
        {
            $insertedFileEntries[] = array(
                'url' => $insertedFileRecord->getWebPath(),
                'name' => $files['files']['name'][0],
                'type' => $insertedFileRecord->mime_type,
                'thumbnail' => $insertedFileRecord->hasThumbnail() ? $insertedFileRecord->getWebPath('thumbnails') : '',
                'size' => $insertedFileRecord->size,
            );
        }
        $response->set('files', $insertedFileEntries);
        $response->set('fails', $failedFiles);
        if ($job->countUploadRows() <= 0)
        {
            $response->set('finished', true);
            $job->finish();
            $job->save();
        }
    }
}
