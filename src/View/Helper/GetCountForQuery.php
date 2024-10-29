<?php

namespace Search\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Search\Query;
use Search\Querier\Exception\QuerierException;

class GetCountForQuery extends AbstractHelper
{
    protected $page;
    protected $index;

    public function __invoke($searchQuery)
    {
        $view = $this->getView();
        $site = $view->currentSite();
        $currentSiteID = $site->id();
        $api = $view->plugin('api');
        $userIsAllowed = $view->plugin('userIsAllowed');
        $searchPages = $api->search('search_pages')->getContent();
        foreach ($searchPages as $searchPage) {
            if (array_key_exists('site', $searchPage->settings()) && ($currentSiteID == $searchPage->settings()['site'])) {
                $this->page = $searchPage;
                $index_id = $this->page->index()->id();
                $response = $api->read('search_indexes', $index_id);
                $this->index = $response->getContent();
                $settings = $this->page->settings();
                $searchFormSettings = $settings['form'];
                $formAdapter = $this->page->formAdapter();
                $query = $formAdapter->toQuery($searchQuery, $searchFormSettings);
                $querier = $this->index->querier();
                $query->setResources(array('items'));
                $query->setSite($site);
                if (!$userIsAllowed('Omeka\Entity\Resource', 'view-all')) {
                    $query->setIsPublic(true);
                }
                if (isset($searchQuery['limit'])) {
                    foreach ($searchQuery['limit'] as $name => $values) {
                        foreach ($values as $value) {
                            $query->addFacetFilter($name, $value);
                        }
                    }
                }
                // Set limit to 0 because we only need facets in results
                $query->setLimitPage(1, 0);

                try {
                    $response = $querier->query($query);
                } catch (QuerierException $e) {
                    return null;
                }
                return $response->getTotalResults();
            }
        }
        return null;
    }
}
