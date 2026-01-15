<?php

namespace Search\Job;

use Omeka\Job\AbstractJob;
use SolrClient;

class DeleteOCRindex extends AbstractJob
{
    public function perform()
    {
        $serviceLocator = $this->getServiceLocator();
        $api = $serviceLocator->get('Omeka\ApiManager');
        $mediaIdList = $this->getArg('mediaIdList');
        $solrNodeId = $this->getArg('solrNodeId');
        $solrNode = $api->read('solr_nodes', $solrNodeId)->getContent();
        $clientSettings = $solrNode->clientSettings();
        $logger = $serviceLocator->get('Omeka\Logger');

        $solrClient = new SolrClient([
            'hostname' => $clientSettings['hostname'],
            'port' => $clientSettings['port'],
            'path' => $clientSettings['solr_ocr_path'],
            'login' => $clientSettings['login'],
            'password' => $clientSettings['password'],
            'wt' => 'json',
        ]);

        foreach ($mediaIdList as $mediaId) {
            $logger->info("Deleting media id: " . $mediaId);
            $solrClient->deleteById($mediaId);
        }
    }
}
