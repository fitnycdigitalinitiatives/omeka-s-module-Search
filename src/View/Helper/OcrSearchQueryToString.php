<?php

namespace Search\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Representation\ItemSetRepresentation;

class OcrSearchQueryToString extends AbstractHelper
{
    public function __invoke(?string $q, ?ItemSetRepresentation $current_item_set)
    {
        $view = $this->getView();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" aria-label="Remove search term:" class="bi bi-x-circle me-1" viewBox="0 0 16 16"><title>Remove search filter:</title> <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16"></path> <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708"></path></svg>';
        $escape = $view->plugin('escapeHtml');
        $urlHelper = $view->plugin('url');
        $html = '<ul id="query" class="list-inline mb-0">';
        if ($q) {
            $q_query = $current_item_set ? ['item_set_id' => $current_item_set->id()] : [];
            $q_url = $urlHelper('site/ocrsearch', [], ['query' => $q_query], true);
            $html .= '<li class="list-inline-item"><a href="' . $q_url . '" class="link-dark remove-query text-decoration-none">' . $svg . $escape($q) . '</a></li>';
        }
        if ($current_item_set) {
            $item_set_id_query = $q ? ['q' => $q] : [];
            $item_set_id_url = $urlHelper('site/ocrsearch', [], ['query' => $item_set_id_query], true);
            $html .= '<li class="list-inline-item"><a href="' . $item_set_id_url . '" class="link-dark remove-query text-decoration-none">' . $svg . $current_item_set->displayTitle() . '</a></li>';
        }
        $search_query = [];
        if ($q) {
            $search_query['q'] = $q;
        }
        if ($current_item_set) {
            $search_query['item_set_id'] = [$current_item_set->id()];
        }
        $search_url = $urlHelper('site/search', [], ['query' => $search_query], true);
        $html .= '<li class="list-inline-item"><a href="' . $search_url . '" class="link-dark remove-query text-decoration-none">' . $svg . 'full-text search</a></li>';
        $html .= '</ul>';
        return $html;
    }
}
