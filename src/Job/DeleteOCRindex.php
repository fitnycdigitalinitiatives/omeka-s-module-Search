<?php

namespace Search\Job;

use Omeka\Job\AbstractJob;
use SolrClient;

class DeleteOCRindex extends AbstractJob
{
    public function perform()
    {
        $serviceLocator = $this->getServiceLocator();
        $mediaIdList = $this->getArg('mediaIdList');
        $logger = $serviceLocator->get('Omeka\Logger');
        $settings = $serviceLocator->get('Omeka\Settings');
        $solr_host = $settings->get('fit_module_solr_hostname');
        $solr_port = $settings->get('fit_module_solr_port');
        $solr_path = $settings->get('fit_module_solr_path');
        $solr_user = $settings->get('fit_module_solr_login');
        $solr_password = $settings->get('fit_module_solr_password');

        $solrClient = new SolrClient([
            'hostname' => $solr_host,
            'port' => $solr_port,
            'path' => $solr_path,
            'login' => $solr_user,
            'password' => $solr_password,
            'wt' => 'json',
        ]);

        foreach ($mediaIdList as $mediaId) {
            $logger->info("Deleting media id: " . $mediaId);
            $solrClient->deleteById($mediaId);
        }
    }
}
