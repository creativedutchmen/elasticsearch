<?php

	require_once(TOOLKIT . '/class.datasource.php');
	require_once(TOOLKIT . '/class.entrymanager.php');
	
	require_once(EXTENSIONS . '/elasticsearch/lib/class.elasticsearch.php');

	Class datasourcesuggest extends Datasource{

		public $dsParamROOTELEMENT = 'elasticsearch-suggest';

		public function __construct(&$parent, $env=NULL, $process_params=true){
			parent::__construct($parent, $env, $process_params);
		}
		
		public function about(){
			return array(
				'name' => 'ElasticSearch: Suggest'
			);
		}

		public function grab(&$param_pool=NULL){
			
			$config = (object)Symphony::Configuration()->get('elasticsearch');
			
			// build an object of runtime parameters
			$params = (object)array(
				'keywords' => isset($_GET['keywords']) ? trim($_GET['keywords']) : '',
				'per-page' => isset($_GET['per-page']) ? $_GET['per-page'] : $config->{'per-page'},
				'sections' =>  isset($_GET['sections']) ? array_map('trim', explode(',', $_GET['sections'])) : NULL,
			);
			
			if(empty($params->keywords)) return;
			// add trailing wildcard if it's not alreadt there
			if(end(str_split($params->keywords)) !== '*') $params->keywords .= '*';
			
			ElasticSearch::init();
			
			$query_querystring = new Elastica_Query_QueryString();
			$query_querystring->setDefaultOperator('AND');
			$query_querystring->setQueryString($params->keywords);
			$query_querystring->setFields(array('*.symphony_autocomplete'));
			
			$query = new Elastica_Query($query_querystring);
			$query->setLimit($params->{'per-page'});
			
			$search = new Elastica_Search(ElasticSearch::getClient());
			$search->addIndex(ElasticSearch::getIndex());
			
			$filter = new Elastica_Filter_Terms('_type');
			
			// build an array of all valid section handles that have mappings
			$all_mapped_sections = array();
			$section_full_names = array();
			foreach(ElasticSearch::getAllTypes() as $type) {
				$all_mapped_sections[] = $type->section->get('handle');
				// cache an array of section names indexed by their handles, quick lookup later
				$section_full_names[$type->section->get('handle')] = $type->section->get('name');
			}
			
			$sections = array();
			// no specified sections were sent in the params, so default to all available sections
			if($params->sections === NULL) {
				$sections = $all_mapped_sections;
			}
			// otherwise filter out any specified sections that we don't have mappings for, in case
			// a user has made a typo or someone is tampering with the URL params
			else {
				foreach($params->sections as $handle) {
					if(!in_array($handle, $all_mapped_sections)) continue;
					$sections[] = $handle;
				}
			}
			
			$autocomplete_fields = array();
			foreach($sections as $section) {
				$filter->addTerm($section);
				$mapping = json_decode(ElasticSearch::getTypeByHandle($section)->mapping_json, FALSE);
				// find fields that have symphony_highlight
				foreach($mapping->{$section}->properties as $field => $properties) {
					if(!$properties->fields->symphony_autocomplete) continue;
					$autocomplete_fields[] = $field;
				}
			}
			
			$autocomplete_fields = array_unique($autocomplete_fields);
			
			$query->setFilter($filter);
			
			// run the entry search
			$entries_result = $search->search($query);
			
			$xml = new XMLElement($this->dsParamROOTELEMENT, NULL, array(
				'took' => $entries_result->getResponse()->getEngineTime() . 'ms',
				'max-score' => $entries_result->getMaxScore()
			));
			
			$xml_entries = new XMLElement('entries');
			foreach($entries_result->getResults() as $data) {
				
				$entry = new XMLElement('entry', NULL, array(
					'id' => $data->getId(),
					'section' => $data->getType(),
					'score' => is_array($data->getScore()) ? reset($data->getScore()) : $data->getScore()
				));
				
				$source = $data->getSource();
				foreach($autocomplete_fields as $handle) {
					if(!isset($source[$handle])) continue;
					$entry->appendChild(new XMLElement($handle, General::sanitize($source[$handle])));
				}
				
				$xml_entries->appendChild($entry);
			}
			
			$xml->appendChild($xml_entries);
			
			return $xml;
		}

	}
