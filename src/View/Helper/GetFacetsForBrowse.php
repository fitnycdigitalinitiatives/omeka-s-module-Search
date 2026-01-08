<?php

namespace Search\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Search\Query;
use Search\Querier\Exception\QuerierException;

class GetFacetsForBrowse extends AbstractHelper
{
    protected $page;
    protected $index;

    public function __invoke($browseQuery, $itemSetShow)
    {
        if (array_key_exists('Search', $browseQuery) || array_key_exists('search', $browseQuery) || array_key_exists('property', $browseQuery)) {
            return null;
        }
        $view = $this->getView();
        $site = $view->currentSite();
        $currentSiteID = $site->id();
        $api = $view->plugin('api');
        $searchPages = $api->search('search_pages')->getContent();
        foreach ($searchPages as $searchPage) {
            if (array_key_exists('site', $searchPage->settings()) && ($currentSiteID == $searchPage->settings()['site'])) {
                $this->page = $searchPage;
                $index_id = $this->page->index()->id();
                $response = $api->read('search_indexes', $index_id);
                $this->index = $response->getContent();
                $query = new Query();
                $querier = $this->index->querier();
                $query->setResources(array('items'));
                $settings = $this->page->settings();
                $searchFormSettings = $settings['form'];
                if (array_key_exists('item_set_id', $browseQuery)) {
                    $itemSetIDs = $browseQuery['item_set_id'];
                    if (!is_array($itemSetIDs)) {
                        $itemSetIDs = [$itemSetIDs];
                    }
                    $itemSetIDs = array_filter($itemSetIDs);
                    if ($itemSetIDs) {
                        $itemSetsField = $searchFormSettings['item_sets_field'] ?? '';
                        if ($itemSetsField) {
                            $query->addFacetFilter($itemSetsField, $itemSetIDs);
                        } else {
                            return null;
                        }
                    }
                }
                foreach ($settings['facets'] as $facet) {
                    $query->addFacetField($facet['name']);
                }
                if (isset($settings['facet_limit'])) {
                    $query->setFacetLimit($settings['facet_limit']);
                }
                if ($name = $settings['date_range_facet_field']) {
                    $query->setDateFacetStatField($name);
                }
                $query->setSite($site);
                if (!$view->userIsAllowed('Omeka\Entity\Resource', 'view-all')) {
                    $query->setIsPublic(true);
                    if ($view->getHelperPluginManager()->has('groupsForCurrentUser')) {
                        $query->setGroups($view->groupsForCurrentUser());
                    }
                }
                // Set limit to 0 because we only need facets in results
                $query->setLimitPage(1, 0);
                try {
                    $response = $querier->query($query);
                } catch (QuerierException $e) {
                    return null;
                }
                $facetCounts = $response->getFacetCounts();
                $facets = [];
                foreach ($settings['facets'] as $facet) {
                    $name = $facet['name'];
                    if (array_key_exists($name, $facetCounts)) {
                        $facets[$name] = $facetCounts[$name];
                    } else {
                        $facets[$name] = [];
                    }
                }
                $totalResults = $response->getTotalResults();
                // Remove facets that are all the results
                foreach ($facets as $facetName => $facetsSet) {
                    foreach ($facetsSet as $facetsSetKey => $facetArray) {
                        if ($facetArray["count"] == $totalResults) {
                            unset($facets[$facetName][$facetsSetKey]);
                        }
                    }
                }
                $dateFacetStats = $response->getDateFacetStats();
                return array('facets' => $facets, 'dateFacetStats' => $dateFacetStats);
            }
        }
        return null;
    }
}
