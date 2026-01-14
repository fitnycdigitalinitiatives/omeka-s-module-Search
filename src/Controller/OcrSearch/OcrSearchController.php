<?php

namespace Search\Controller\IiifSearch\v1;

use Laminas\Mvc\Controller\AbstractActionController;
use Omeka\Mvc\Exception\RuntimeException;
use SolrClient;
use SolrClientException;
use SolrQuery;

class IiifSearchController extends AbstractActionController
{
    public function searchAction()
    {
        if (($settings = $this->settings()) && ($settings->get('fit_module_solr_connection')) && ($host = $settings->get('fit_module_solr_hostname')) && ($port = $settings->get('fit_module_solr_port')) && ($path = $settings->get('fit_module_solr_path')) && ($params = $this->params()->fromQuery()) && array_key_exists('q', $params) && ($q = $params['q'])) {
            $response = $this->getResponse();
            $client = new SolrClient([
                'hostname' => $host,
                'port' => $port,
                'path' => $path,
                'login' => $settings->get('fit_module_solr_login') ? $settings->get('fit_module_solr_login') : "",
                'password' => $settings->get('fit_module_solr_password') ? $settings->get('fit_module_solr_password') : "",
                'wt' => 'json',
            ]);
            $solrQuery = new SolrQuery;
            $solrQuery->setQuery('ocr_text:"' . urldecode($q) . '"');
            $solrQuery->setHighlight(true);
            $solrQuery->setHighlightSnippets(2);
            $solrQuery->addparam('hl.ocr.fl', 'ocr_text');
            // $solrQuery->addFilterQuery('media_id:' . $media_id);
            // $solrQuery->addField('media_id');
        } else {
            throw new RuntimeException("Invalid Page");
        }
    }
}
