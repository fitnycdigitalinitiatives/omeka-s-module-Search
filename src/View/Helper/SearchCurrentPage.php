<?php

namespace Search\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Search\Api\Representation\SearchPageRepresentation;

class SearchCurrentPage extends AbstractHelper
{
    protected $searchPage;

    public function __invoke(): SearchPageRepresentation
    {
        $view = $this->getView();
        $api = $view->plugin('api');
        $searchPages = $api->search('search_pages')->getContent();
        $currentSiteID = $view->currentSite()->id();
        foreach ($searchPages as $thisSearchPage) {
            if (array_key_exists('site', $thisSearchPage->settings()) && ($currentSiteID == $thisSearchPage->settings()['site'])) {
                return $thisSearchPage;
            }
        }
    }

    public function setSearchPage(SearchPageRepresentation $searchPage)
    {
        $this->searchPage = $searchPage;
    }
}
