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

namespace Search\Form\Admin;

use Laminas\Form\Form;
use Laminas\I18n\Translator\TranslatorAwareInterface;
use Laminas\I18n\Translator\TranslatorAwareTrait;
use Search\Form\Element\Fields;
use Omeka\Form\Element\SiteSelect;

class SearchPageConfigureForm extends Form implements TranslatorAwareInterface
{
    use TranslatorAwareTrait;

    protected $formElementManager;

    protected $urlViewHelper;

    public function init()
    {
        $translator = $this->getTranslator();

        $searchPage = $this->getOption('search_page');
        $adapter = $searchPage->index()->adapter();
        $settings = $searchPage->settings();

        $this->add([
            'name' => 'site',
            'type' => SiteSelect::class,
            'options' => [
                'label' => $translator->translate('Site'),
                'info' => 'Select which site should be associated with this page. NOTE: Do not associate a site with more than one page.',
                // @translate
                'empty_option' => '',
            ],
            'attributes' => [
                'class' => 'chosen-select',
                'required' => true,
            ],
        ]);

        $this->add([
            'name' => 'save_queries',
            'type' => 'Checkbox',
            'options' => [
                'label' => $translator->translate('Save queries'),
                'info' => $translator->translate('Add ability to save your query and reload it later with result update'),
            ],
        ]);

        $this->add([
            'name' => 'facet_limit',
            'type' => 'Number',
            'options' => [
                'label' => $translator->translate('Facet limit'),
                'info' => $translator->translate('The maximum number of values fetched for each facet. Set to -1 for unlimited facets.'),
            ],
            'attributes' => [
                'min' => '-1',
                'required' => true,
            ],
        ]);

        $facetFields = $adapter->getAvailableFacetFields($searchPage->index());
        $facetValueOptions = array_column($facetFields, 'label', 'name');
        $url = $this->urlViewHelper;
        $this->add([
            'name' => 'facets',
            'type' => Fields::class,
            'options' => [
                'label' => 'Facets', // @translate
                'empty_option' => 'Add a facet', // @translate
                'value_options' => $facetValueOptions,
                'field_list_url' => $url('admin/search/facets', ['action' => 'field-list'], ['query' => ['search_page_id' => $searchPage->id()]]),
                'field_row_url' => $url('admin/search/facets', ['action' => 'field-row'], ['query' => ['search_page_id' => $searchPage->id()]]),
                'field_edit_sidebar_url' => $url('admin/search/facets', ['action' => 'field-edit-sidebar'], ['query' => ['search_page_id' => $searchPage->id()]]),
            ],
        ]);

        $this->add([
            'name' => 'date_range_facet_field',
            'type' => 'Text',
            'options' => [
                'label' => $translator->translate('Date range facet field'),
                'info' => $translator->translate('Date field used to create a range slider in the facets. Uses Solr Stat function to get min and max values, so DateYear value formatter must be use to format string to be year, ie not "ca. 2005".'),
                'empty_option' => '',
            ],
            'attributes' => [
                'placeholder' => 'dcterms_date_ss',
            ],
        ]);

        $sortFields = $adapter->getAvailableSortFields($searchPage->index());
        $sortFieldValueOptions = array_column($sortFields, 'label', 'name');
        $this->add([
            'name' => 'sort_fields',
            'type' => Fields::class,
            'options' => [
                'label' => 'Sort fields', // @translate
                'empty_option' => 'Add a sort field', // @translate
                'value_options' => $sortFieldValueOptions,
                'field_list_url' => $url('admin/search/sort-fields', ['action' => 'field-list'], ['query' => ['search_page_id' => $searchPage->id()]]),
                'field_row_url' => $url('admin/search/sort-fields', ['action' => 'field-row'], ['query' => ['search_page_id' => $searchPage->id()]]),
                'field_edit_sidebar_url' => $url('admin/search/sort-fields', ['action' => 'field-edit-sidebar'], ['query' => ['search_page_id' => $searchPage->id()]]),
            ],
        ]);

        $formFieldset = $this->getFormFieldset();
        if ($formFieldset) {
            $this->add($formFieldset);
        }
    }

    public function setFormElementManager($formElementManager)
    {
        $this->formElementManager = $formElementManager;
    }

    public function getFormElementManager()
    {
        return $this->formElementManager;
    }

    public function setUrlViewHelper($urlViewHelper)
    {
        $this->urlViewHelper = $urlViewHelper;
    }

    protected function getFormFieldset()
    {
        $formElementManager = $this->getFormElementManager();
        $searchPage = $this->getOption('search_page');

        $formAdapter = $searchPage->formAdapter();
        if (!isset($formAdapter)) {
            return null;
        }

        $configFormClass = $formAdapter->getConfigFormClass();
        if (!isset($configFormClass)) {
            return null;
        }

        $fieldset = $formElementManager->get($configFormClass, [
            'search_page' => $searchPage,
        ]);
        $fieldset->setName('form');
        $fieldset->setLabel($this->getTranslator()->translate('Form settings'));

        return $fieldset;
    }

    protected function getFieldLabel($field, $settings_key)
    {
        $searchPage = $this->getOption('search_page');
        $settings = $searchPage->settings();

        $name = $field['name'];
        $label = $field['label'] ?? null;
        if (isset($settings[$settings_key][$name])) {
            $fieldSettings = $settings[$settings_key][$name];

            if (!empty($fieldSettings['display']['label'])) {
                $label = $fieldSettings['display']['label'];
            }
        }
        $label = $label ? sprintf('%s (%s)', $label, $field['name']) : $field['name'];

        return $label;
    }

    protected function getSortFieldLabel($field)
    {
        return $this->getFieldLabel($field, 'sort_fields');
    }

    protected function getFacetFieldLabel($field)
    {
        return $this->getFieldLabel($field, 'facets');
    }
}
