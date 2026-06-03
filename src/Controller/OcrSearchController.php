<?php

namespace Search\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\Session\Container;
use Laminas\Authentication\AuthenticationService;
use Omeka\Mvc\Exception\RuntimeException;
use Omeka\Mvc\Exception\NotFoundException;
use Omeka\Stdlib\Paginator;
use SolrClient;
use SolrClientException;
use SolrQuery;

class OcrSearchController extends AbstractActionController
{
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
        $settings = $this->getSearchPage()->settings();
        if (isset($settings['ocr_search']) && $settings['ocr_search']) {
            $sessionManager = Container::getDefaultManager();
            $session = $sessionManager->getStorage();
            $site = $this->currentSite();
            $site_slug = $site->slug();
            $turnstileAuth = $session->offsetGet($site_slug . '_turnstile_authorization');
            if ($this->settings()->get('search_module_activate_turnstile', false) && !$this->auth->hasIdentity() && !$turnstileAuth) {
                return $this->redirect()->toRoute('site/challenge', ['site-slug' => $site_slug], ['query' => ['redirect_url' => $this->getRequest()->getUriString()]]);
            }
            $view = new ViewModel;
            $totalResults = 0;
            $results = [];
            $facets = [];
            $params = $this->params()->fromQuery();
            $q = array_key_exists('q', $params) ? $params['q'] : "";
            $siteSetting = $this->viewHelpers()->get('siteSetting');
            $per_page = $siteSetting('pagination_per_page', $this->settings()->get('pagination_per_page', Paginator::PER_PAGE));
            $page_number = 1;
            $item_set_id = array_key_exists('item_set_id', $params) ? $params['item_set_id'] : "";
            $api = $this->api();
            $solr_config = null;
            $solr_nodes = $api->search('solr_nodes')->getContent();
            foreach ($solr_nodes as $solr_node) {
                $clientSettings = $solr_node->clientSettings();
                if (array_key_exists('solr_ocr_connection', $clientSettings) && $clientSettings['solr_ocr_connection'] && array_key_exists('solr_ocr_path', $clientSettings) && ($solr_ocr_path = $clientSettings['solr_ocr_path']) && array_key_exists('hostname', $clientSettings) && ($hostname = $clientSettings['hostname']) && array_key_exists('port', $clientSettings) && ($port = $clientSettings['port']) && array_key_exists('login', $clientSettings) && ($login = $clientSettings['login']) && array_key_exists('password', $clientSettings) && ($password = $clientSettings['password'])) {
                    $solr_config = [
                        'hostname' => $hostname,
                        'port' => $port,
                        'path' => $solr_ocr_path,
                        'login' => $login,
                        'password' => $password,
                        'wt' => 'json',
                    ];
                    break;
                }
            }
            if ($solr_config) {
                $client = new SolrClient($solr_config);
                $solrQuery = new SolrQuery;
                $solrQuery->addFilterQuery("sites:" . $site->id());
                if (!$this->userIsAllowed('Omeka\Entity\Resource', 'view-all')) {
                    $fq = "is_public:true";
                    $user = $this->identity();
                    if ($user && $this->getPluginManager()->has('listGroups')) {
                        $groups = $this->listGroups($api->read('users', $user->getId())->getContent(), 'id');
                        if (isset($groups)) {
                            foreach ($groups as $group) {
                                $fq = sprintf('%s OR %s:%s', $fq, "groups", $group);
                            }
                        }
                    }
                    $solrQuery->addFilterQuery($fq);
                }
                if ($q) {
                    $solrQuery->setQuery('ocr_text:"' . urldecode($q) . '"');
                    $solrQuery->setHighlight(true);
                    $solrQuery->setHighlightSnippets(1);
                    $solrQuery->addparam('hl.ocr.fl', 'ocr_text');
                    $solrQuery->addparam('hl.weightMatches', 'true');
                } else {
                    $solrQuery->setQuery('ocr_text:*');
                }
                $solrQuery->addField('media_id');
                $solrQuery->addField('item_id');
                if ($item_set_id) {
                    $solrQuery->addFilterQuery('item_set_ids:' . $item_set_id);
                }
                if (array_key_exists('page', $params) && $params['page']) {
                    $page_number = $params['page'];
                }
                $solrQuery->setRows($per_page);
                $offset = $per_page * ($page_number - 1);
                $solrQuery->setStart($offset);
                $solrQuery->setFacet(true);
                $solrQuery->setFacetMinCount(1);
                $solrQuery->addFacetField('item_set_ids');
                $solrQuery->setFacetLimit(-1);
                $solrQuery->setFacetSort(SolrQuery::FACET_SORT_COUNT);
                $solrQuery->addparam('json.nl', 'map');
                try {
                    $solrQueryResponse = $client->query($solrQuery)->getResponse();
                } catch (SolrClientException $e) {
                    $this->messenger()->addError("There was an error during your search. Please try again. If you continue to run into issues. please contact us at repository@fitnyc.edu");
                    return $view;
                }
                $totalResults = $solrQueryResponse["response"]["numFound"];
                if ($totalResults > 0) {
                    foreach ($solrQueryResponse["response"]["docs"] as $doc) {
                        if ($q && array_key_exists($doc["media_id"], $solrQueryResponse["ocrHighlighting"])) {
                            $doc["snippet_text"] = $solrQueryResponse["ocrHighlighting"][$doc["media_id"]]['ocr_text']['snippets'][0]['text'];
                            $doc["snippet_page"] = $solrQueryResponse["ocrHighlighting"][$doc["media_id"]]['ocr_text']['snippets'][0]['pages'][0]['id'];
                        }
                        $results[] = $doc;
                    }
                    if (isset($solrQueryResponse["facet_counts"]["facet_fields"]["item_set_ids"])) {
                        foreach ($solrQueryResponse["facet_counts"]["facet_fields"]["item_set_ids"] as $value => $count) {
                            if ($count != $totalResults) {
                                $facets[] = ['item_set_id' => $value, 'count' => $count];
                            }
                        }
                    }
                }
            } else {
                throw new RuntimeException("No Solr OCR connection");
            }
            $view->setVariable('totalResults', $totalResults);
            $view->setVariable('results', $results);
            $view->setVariable('facets', $facets);
            $view->setVariable('params', $params);
            $view->setVariable('q', $q);
            $view->setVariable('per_page', $per_page);
            $view->setVariable('current_page', $page_number);
            $view->setVariable('item_set_id', $item_set_id);
            return $view;
        } else {
            throw new NotFoundException("Invalid Page");
        }
    }
    public function itemsetsAction()
    {
        $response = $this->getResponse();
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $api = $this->api();
        $solr_config = null;
        $solr_nodes = $api->search('solr_nodes')->getContent();
        foreach ($solr_nodes as $solr_node) {
            $clientSettings = $solr_node->clientSettings();
            if (array_key_exists('solr_ocr_connection', $clientSettings) && $clientSettings['solr_ocr_connection'] && array_key_exists('solr_ocr_path', $clientSettings) && ($solr_ocr_path = $clientSettings['solr_ocr_path']) && array_key_exists('hostname', $clientSettings) && ($hostname = $clientSettings['hostname']) && array_key_exists('port', $clientSettings) && ($port = $clientSettings['port']) && array_key_exists('login', $clientSettings) && ($login = $clientSettings['login']) && array_key_exists('password', $clientSettings) && ($password = $clientSettings['password'])) {
                $solr_config = [
                    'hostname' => $hostname,
                    'port' => $port,
                    'path' => $solr_ocr_path,
                    'login' => $login,
                    'password' => $password,
                    'wt' => 'json',
                ];
                break;
            }
        }
        if ($solr_config) {
            $client = new SolrClient($solr_config);
            $solrQuery = new SolrQuery;
            $solrQuery->addFilterQuery("sites:" . $this->currentSite()->id());
            if (!$this->userIsAllowed('Omeka\Entity\Resource', 'view-all')) {
                $fq = "is_public:true";
                $user = $this->identity();
                if ($user && $this->getPluginManager()->has('listGroups')) {
                    $groups = $this->listGroups($api->read('users', $user->getId())->getContent(), 'id');
                    if (isset($groups)) {
                        foreach ($groups as $group) {
                            $fq = sprintf('%s OR %s:%s', $fq, "groups", $group);
                        }
                    }
                }
                $solrQuery->addFilterQuery($fq);
            }
            $solrQuery->setQuery('ocr_text:*');
            $solrQuery->setRows(0);
            $solrQuery->setFacet(true);
            $solrQuery->setFacetMinCount(1);
            $solrQuery->addFacetField('item_set_ids');
            $solrQuery->setFacetLimit(-1);
            $solrQuery->setFacetSort(SolrQuery::FACET_SORT_COUNT);
            $solrQuery->addparam('json.nl', 'map');
            try {
                $solrQueryResponse = $client->query($solrQuery)->getResponse();
            } catch (SolrClientException $e) {
                $error = array('error' => ["code" => 500, "message" => $e->getMessage()]);
                $response->setStatusCode(500);
                $response->setContent(json_encode($error));
                return $response;
            }
            $collections = [];
            $totalResults = $solrQueryResponse["response"]["numFound"];
            if (($totalResults > 0) && isset($solrQueryResponse["facet_counts"]["facet_fields"]["item_set_ids"])) {
                foreach ($solrQueryResponse["facet_counts"]["facet_fields"]["item_set_ids"] as $value => $count) {
                    if ($count != $totalResults) {
                        $this_item_set = null;
                        try {
                            $this_item_set = $api->read('item_sets', $value)->getContent();
                        } catch (Omeka\Api\Exception\NotFoundException $e) {
                            $this_item_set = null;
                        }
                        if ($this_item_set && (strtolower($this_item_set->displayTitle()) != "special collections and fit archive")) {
                            $collections[] = ['id' => $value, 'count' => $count, 'title' => $this_item_set->displayTitle()];
                        }
                    }
                }
            }
            $response->setContent(json_encode($collections));
            return $response;
        } else {
            $error = array('error' => ["code" => 500, "message" => "No Solr OCR connection"]);
            $response->setStatusCode(500);
            $response->setContent(json_encode($error));
            return $response;
        }
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
