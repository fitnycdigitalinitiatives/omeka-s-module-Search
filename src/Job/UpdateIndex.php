<?php

namespace Search\Job;

use Omeka\Job\AbstractJob;

class UpdateIndex extends AbstractJob
{
    public function perform()
    {
        $serviceLocator = $this->getServiceLocator();
        $api = $serviceLocator->get('Omeka\ApiManager');
        $em = $serviceLocator->get('Omeka\EntityManager');
        $logger = $serviceLocator->get('Omeka\Logger');

        $ids = $this->getArg('ids', []);
        if (empty($ids)) {
            return;
        }

        $resources = $em->getRepository('Omeka\Entity\Resource')->findBy(['id' => $ids]);
        if (empty($resources)) {
            return;
        }

        $searchIndexes = $api->search('search_indexes')->getContent();
        foreach ($searchIndexes as $searchIndex) {
            $searchIndexSettings = $searchIndex->settings();
            // Filter out items that are part of non-indexed item sets
            if (array_key_exists('collections', $searchIndexSettings) && ($itemSetIDs = $searchIndexSettings['collections'])) {
                $filterSetsResources = array_filter($resources, function ($resource) use ($itemSetIDs) {
                    if ($resource->getResourceName() == 'items') {
                        foreach ($resource->getItemSets() as $itemSet) {
                            if (in_array($itemSet->getId(), $itemSetIDs)) {
                                return false;
                            }
                        }
                    }
                    return true;
                });
                $resources = $filterSetsResources;
                if (empty($resources)) {
                    return;
                }
            }
            $filteredResources = array_filter($resources, fn($resource) => in_array($resource->getResourceName(), $searchIndexSettings['resources']));
            if (empty($filteredResources)) {
                continue;
            }

            try {
                $indexer = $searchIndex->indexer();
                $indexer->indexResources($filteredResources);
            } catch (\Exception $e) {
                $logger->err(sprintf('Search: failed to index resources: %s', $e));
            }
        }
    }
}
