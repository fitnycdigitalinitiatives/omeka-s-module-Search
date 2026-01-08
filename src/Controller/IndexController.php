<?php

/*
 * Copyright BibLibre, 2016-2017
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace Search\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\Session\Container;
use Laminas\Authentication\AuthenticationService;
use Omeka\Mvc\Exception\RuntimeException;
use Omeka\Stdlib\Paginator;
use Search\Querier\Exception\QuerierException;
use SolrClient;
use SolrClientException;
use SolrQuery;

class IndexController extends AbstractActionController
{
    protected $page;
    protected $index;

    /**
     * @var AuthenticationService
     */
    protected $auth;

    /**
     * @param AuthenticationService $auth
     */

    public function __construct(AuthenticationService $auth)
    {
        $this->auth = $auth;
    }

    public function searchAction()
    {
        $sessionManager = Container::getDefaultManager();
        $session = $sessionManager->getStorage();
        $site = $this->currentSite();
        $site_slug = $site->slug();
        $turnstileAuth = $session->offsetGet($site_slug . '_turnstile_authorization');
        if ($this->settings()->get('search_module_activate_turnstile', false) && !$this->auth->hasIdentity() && !$turnstileAuth) {
            return $this->redirect()->toRoute('site/challenge', ['site-slug' => $site_slug], ['query' => ['redirect_url' => $this->getRequest()->getUriString()]]);
        }
        $this->page = $this->getSearchPage();
        $index_id = $this->page->index()->id();

        $form = $this->searchForm($this->page);

        $view = new ViewModel;
        $params = $this->params()->fromQuery();

        $form->setData($params);
        if (!$form->isValid()) {
            $this->messenger()->addError('There was an error during validation');
            return $view;
        }

        $searchPageSettings = $this->page->settings();
        $searchFormSettings = [];
        if (isset($searchPageSettings['form'])) {
            $searchFormSettings = $searchPageSettings['form'];
        }

        $formAdapter = $this->page->formAdapter();
        if (!isset($formAdapter)) {
            $formAdapterName = $this->page->formAdapterName();
            $msg = sprintf("Form adapter '%s' not found", $formAdapterName);
            throw new RuntimeException($msg);
        }

        $query = $formAdapter->toQuery($params, $searchFormSettings);
        $response = $this->api()->read('search_indexes', $index_id);
        $this->index = $response->getContent();

        $querier = $this->index->querier();

        $indexSettings = $this->index->settings();
        if (array_key_exists('resource_type', $params)) {
            $resource_type = $params['resource_type'];
            if (!is_array($resource_type)) {
                $resource_type = [$resource_type];
            }
            $query->setResources($resource_type);
        } else {
            $query->setResources($indexSettings['resources']);
        }

        $settings = $this->page->settings();
        foreach ($settings['facets'] as $facet) {
            $query->addFacetField($facet['name']);
        }
        if (isset($settings['facet_limit'])) {
            $query->setFacetLimit($settings['facet_limit']);
        }

        if (isset($settings['facet_stats'])) {
            $query->setFacetStatsEnabled($settings['facet_stats']);
        }

        if (isset($params['limit'])) {
            foreach ($params['limit'] as $name => $values) {
                foreach ($values as $value) {
                    $query->addFacetFilter($name, $value);
                }
            }
        }

        if ($name = $settings['date_range_facet_field']) {
            $query->setDateFacetStatField($name);
        }

        $query->setSite($site);

        if (!$this->userIsAllowed('Omeka\Entity\Resource', 'view-all')) {
            $query->setIsPublic(true);
            $user = $this->identity();
            if ($user && $this->getPluginManager()->has('listGroups')) {
                $query->setGroups($this->listGroups($this->api()->read('users', $user->getId())->getContent(), 'id'));
            }
        }

        $sortOptions = $this->getSortOptions();

        if (isset($params['sort'])) {
            $sort = $params['sort'];
        } elseif (isset($params['q']) && !empty($params['q'])) {
            $sort = "score desc";
        } else {
            reset($sortOptions);
            $sort = key($sortOptions);
        }

        $query->setSort($sort);
        $page_number = $params['page'] ?? 1;
        $this->setPagination($query, $page_number);
        try {
            $response = $querier->query($query);
        } catch (QuerierException $e) {
            $this->messenger()->addError('Query error: ' . $e->getMessage());
            return $view;
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
        // Remove facets that are all the results
        $totalResults = $response->getTotalResults();
        foreach ($facets as $facetName => $facetsSet) {
            foreach ($facetsSet as $facetsSetKey => $facetArray) {
                if ($facetArray["count"] == $totalResults) {
                    unset($facets[$facetName][$facetsSetKey]);
                }
            }
        }
        $dateFacetStats = $response->getDateFacetStats();
        $facetPagination = $response->getFacetPagination();
        $saveQueryParam = $this->page->settings()['save_queries'] ?? false;

        $queryParams = json_encode($this->params()->fromQuery());
        $searchPageId = $this->page->id();

        $totalResults = array_map(function ($resource) use ($response) {
            return $response->getResourceTotalResults($resource);
        }, $indexSettings['resources']);
        $this->paginator(max($totalResults), $page_number);
        $view->setVariable('query', $query);
        $view->setVariable('site', $site);
        $view->setVariable('response', $response);
        $view->setVariable('facets', $facets);
        $view->setVariable('dateFacetStats', $dateFacetStats);
        $view->setVariable('dateFacetStatsPlacement', (isset($settings['date_range_facet_field_insert'])) ? $settings['date_range_facet_field_insert'] : "");
        $view->setVariable('facetPagination', $facetPagination);
        $view->setVariable('saveQueryParam', $saveQueryParam);
        $view->setVariable('sortOptions', $sortOptions);
        $view->setVariable('queryParams', $queryParams);
        $view->setVariable('searchPageId', $searchPageId);
        $view->setVariable('searchPageId', $searchPageId);

        return $view;
    }

    public function facetAction()
    {
        $response = $this->getResponse();
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $response->setStatusCode(500);
        $data = ['error' => 'parameters invalid'];
        $sessionManager = Container::getDefaultManager();
        $session = $sessionManager->getStorage();
        $site = $this->currentSite();
        $site_slug = $site->slug();
        $turnstileAuth = $session->offsetGet($site_slug . '_turnstile_authorization');
        if ($this->settings()->get('search_module_activate_turnstile', false) && !$this->auth->hasIdentity() && !$turnstileAuth) {
            return $this->redirect()->toRoute('site/challenge', ['site-slug' => $site_slug], ['query' => ['redirect_url' => $this->getRequest()->getUriString()]]);
        }
        $params = $this->params()->fromQuery();
        if (array_key_exists('facet_name', $params) && ($facet_name = $params['facet_name']) && array_key_exists('facet_page', $params) && ($facet_page = $params['facet_page']) && is_numeric($facet_page) && array_key_exists('per_page', $params) && ($per_page = $params['per_page']) && is_numeric($per_page) && array_key_exists('sort', $params) && ($sort = $params['sort']) && (($sort == 'count') || ($sort == 'index'))) {
            $this->page = $this->getSearchPage();
            $index_id = $this->page->index()->id();
            $searchPageSettings = $this->page->settings();
            $searchFormSettings = [];
            if (isset($searchPageSettings['form'])) {
                $searchFormSettings = $searchPageSettings['form'];
            }

            $formAdapter = $this->page->formAdapter();
            if (isset($formAdapter)) {
                $query = $formAdapter->toQuery($params, $searchFormSettings);
                $this->index = $this->api()->read('search_indexes', $index_id)->getContent();
                $indexSettings = $this->index->settings();
                if (array_key_exists('resource_type', $params)) {
                    $resource_type = $params['resource_type'];
                    if (!is_array($resource_type)) {
                        $resource_type = [$resource_type];
                    }
                    $query->setResources($resource_type);
                } else {
                    $query->setResources($indexSettings['resources']);
                }
                $query->addFacetField($facet_name);
                $query->setFacetLimit($per_page);
                $facet_offset = $per_page * ($facet_page - 1);
                $query->setFacetOffset($facet_offset);
                $query->setFacetSort($sort);

                if (isset($params['limit'])) {
                    foreach ($params['limit'] as $name => $values) {
                        foreach ($values as $value) {
                            $query->addFacetFilter($name, $value);
                        }
                    }
                }

                $query->setSite($site);

                if (!$this->userIsAllowed('Omeka\Entity\Resource', 'view-all')) {
                    $query->setIsPublic(true);
                    $user = $this->identity();
                    if ($user && $this->getPluginManager()->has('listGroups')) {
                        $query->setGroups($this->listGroups($this->api()->read('users', $user->getId())->getContent(), 'id'));
                    }
                }

                $query->setLimitPage(1, 0);

                try {
                    $querier = $this->index->querier();
                    $queryResponse = $querier->query($query);
                } catch (QuerierException $e) {
                    $data = ['error' => $e->getMessage()];
                    $response->setContent(json_encode($data));
                    return $response;
                }

                $data = [];
                $facetCounts = $queryResponse->getFacetCounts();

                $facet_query_keys = ['facet_name', 'facet_page', 'per_page', 'sort'];
                // Remove facets that are all the results
                $totalResults = $queryResponse->getTotalResults();
                foreach ($facetCounts as $facetName => $facetsSet) {
                    foreach ($facetsSet as $facetsSetKey => $facetArray) {
                        if ($facetArray["count"] != $totalResults) {
                            $newQuery = $params;
                            if (isset($newQuery['limit'][$facet_name]) && false !== array_search($facetArray['value'], $newQuery['limit'][$facet_name])) {
                                $values = $newQuery['limit'][$facet_name];
                                $values = array_filter($values, function ($v) use ($facetArray) {
                                    return $v != $facetArray['value'];
                                });
                                $newQuery['limit'][$facet_name] = $values;
                                $active = true;
                            } else {
                                $newQuery['limit'][$facet_name][] = $facetArray['value'];
                            }
                            unset($newQuery['page']);
                            foreach ($facet_query_keys as $remove_key) {
                                unset($newQuery[$remove_key]);
                            }
                            $facetArray['url'] = $this->url()->fromRoute('site/search', ['__NAMESPACE__' => 'Search\Controller', 'controller' => 'index', 'action' => 'search'], ['query' => $newQuery], true);
                            $data[] = $facetArray;
                        }
                    }
                }

                $response->setStatusCode(200);
            } else {
                $formAdapterName = $this->page->formAdapterName();
                $data = ['error' => sprintf("Form adapter '%s' not found", $formAdapterName)];
            }
        }
        $response->setContent(json_encode($data));
        return $response;
    }

    public function suggesterAction()
    {
        $response = $this->getResponse();
        $this->page = $this->getSearchPage();
        $indexSettings = $this->page->index()->settings();
        if (array_key_exists('suggester', $indexSettings)) {
            if ($indexSettings['suggester']) {
                if (array_key_exists('adapter', $indexSettings)) {
                    if (array_key_exists('solr_node_id', $indexSettings['adapter'])) {
                        $solrNode = $this->api()->read('solr_nodes', $indexSettings['adapter']['solr_node_id'])->getContent();
                        $solrNodeSettings = $solrNode->settings();
                        $resource_name_field = $solrNodeSettings['resource_name_field'];
                        $sites_field = $solrNodeSettings['sites_field'];
                        $is_public_field = $solrNodeSettings['is_public_field'];
                        $groups_field = $solrNodeSettings['groups_field'];
                        $resources = $indexSettings['resources'];
                        $client = new SolrClient($solrNode->clientSettings());
                        $solrQuery = new SolrQuery;
                        $solrQuery->setQuery('*:*');
                        $fq = sprintf('%s:%d', $sites_field, $this->currentSite()->id());
                        $solrQuery->addFilterQuery($fq);
                        if (!$this->userIsAllowed('Omeka\Entity\Resource', 'view-all')) {
                            $fq = sprintf('%s:%s', $is_public_field, 'true');
                            $user = $this->identity();
                            if ($user && $this->getPluginManager()->has('listGroups')) {
                                $groups = $this->listGroups($this->api()->read('users', $user->getId())->getContent(), 'id');
                                if (isset($groups)) {
                                    foreach ($groups as $group) {
                                        $fq = sprintf('%s OR %s:%s', $fq, $groups_field, $group);
                                    }
                                }
                            }
                            $solrQuery->addFilterQuery($fq);
                        }
                        $fq = sprintf('%s:(%s)', $resource_name_field, implode(' OR ', $resources));
                        $solrQuery->addFilterQuery($fq);
                        $solrQuery->setFacet(true);
                        $solrQuery->addFacetField($indexSettings['suggester']);
                        $solrQuery->setFacetLimit(-1);
                        $solrQuery->setFacetMinCount(1);
                        $solrQuery->setRows(0);
                        $solrQuery->setFacetSort(SolrQuery::FACET_SORT_COUNT);
                        $solrQuery->setOmitHeader(true);

                        try {
                            $solrQueryResponse = $client->query($solrQuery);
                        } catch (SolrClientException $e) {
                            throw new QuerierException($e->getMessage(), $e->getCode(), $e->getPrevious());
                        }
                        $solrResponse = $solrQueryResponse->getResponse();
                        $formattedArray = array();
                        foreach ($solrResponse["facet_counts"]["facet_fields"][$indexSettings['suggester']] as $key => $value) {
                            $subArray = array('term' => $key, 'count' => $value);
                            array_push($formattedArray, $subArray);
                        }
                        $response->setContent(json_encode($formattedArray));
                        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
                        return $response;
                    }
                }
            }
        }
    }

    protected function setPagination($query, $page)
    {
        $per_page = $this->settings()->get('pagination_per_page', Paginator::PER_PAGE);
        $query->setLimitPage($page, $per_page);
    }

    protected function sortByWeight($fields, $setting_name)
    {
        $settings = $this->page->settings();
        uksort($fields, function ($a, $b) use ($settings, $setting_name) {
            $aWeight = $settings[$setting_name][$a]['weight'];
            $bWeight = $settings[$setting_name][$b]['weight'];
            return $aWeight - $bWeight;
        });
        return $fields;
    }

    protected function getSortOptions()
    {
        $sortOptions = [];

        $sortFields = $this->index->adapter()->getAvailableSortFields($this->index);
        $sortFieldsMap = array_combine(array_column($sortFields, 'name'), $sortFields);
        $settings = $this->page->settings();
        foreach ($settings['sort_fields'] as $sort_field) {
            $name = $sort_field['name'];
            $label = $sort_field['label'] ?? $sortFieldsMap[$name]['label'] ?? $name;

            $sortOptions[$name] = $label;
        }

        return $sortOptions;
    }
    protected function getSearchPage()
    {
        $searchPages = $this->api()->search('search_pages')->getContent();
        $currentSiteID = $this->currentSite()->id();
        foreach ($searchPages as $searchPage) {
            if (array_key_exists('site', $searchPage->settings()) && ($currentSiteID == $searchPage->settings()['site'])) {
                return $searchPage;
            }
        }
        //if no search page is found throw exception
        throw new RuntimeException("A search page for this site is not properly configured.");
    }
}
