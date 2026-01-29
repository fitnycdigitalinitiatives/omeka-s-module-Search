<?php

namespace Search\Job;

use Omeka\Job\AbstractJob;
use Aws\S3\S3MultiRegionClient;
use SolrClient;
use SolrInputDocument;
use Search\Job\OcrConverter\EventKind;
use Search\Job\OcrConverter\BoxType;
use Search\Job\OcrConverter\ParseEvent;
use XMLReader;

class IndexOcr extends AbstractJob
{
    public function perform()
    {
        $serviceLocator = $this->getServiceLocator();
        $mediaIdList = $this->getArg('mediaIdList');
        $solrNodeId = $this->getArg('solrNodeId');
        $logger = $serviceLocator->get('Omeka\Logger');
        $api = $serviceLocator->get('Omeka\ApiManager');
        $settings = $serviceLocator->get('Omeka\Settings');
        $em = $serviceLocator->get('Omeka\EntityManager');
        $controllerPlugins = $serviceLocator->get('ControllerPluginManager');
        $listGroups = null;
        if ($controllerPlugins->has('listGroups')) {
            $listGroups = $controllerPlugins->get('listGroups');
        }

        $aws_key = $settings->get('fit_module_aws_key');
        $aws_secret_key = $settings->get('fit_module_aws_secret_key');
        $solrNode = $api->read('solr_nodes', $solrNodeId)->getContent();
        $clientSettings = $solrNode->clientSettings();

        $s3Client = new S3MultiRegionClient([
            'version' => 'latest',
            'credentials' => [
                'key' => $aws_key,
                'secret' => $aws_secret_key,
            ],
        ]);
        $solrClient = new SolrClient([
            'hostname' => $clientSettings['hostname'],
            'port' => $clientSettings['port'],
            'path' => $clientSettings['solr_ocr_path'],
            'login' => $clientSettings['login'],
            'password' => $clientSettings['password'],
            'wt' => 'json',
        ]);

        foreach ($mediaIdList as $mediaId) {
            $logger->info("Starting process to index media: " . $mediaId);
            $media = $api->read('media', $mediaId, [], ['responseContent' => 'resource'])->getContent();
            $item = $media->getItem();
            $itemId = $item->getId();
            $itemSetsIds = [];
            foreach ($item->getItemSets() as $itemSet) {
                $itemSetsIds[] = $itemSet->getId();
            }
            $isPublic = ($item->isPublic() && $media->isPublic()) ? true : false;
            $groups = [];
            if ($listGroups) {
                $itemRepresentation = $api->read('items', $item->getId())->getContent();
                $groups = $listGroups($itemRepresentation, 'id');
            }
            $sites = [];
            foreach ($item->getSites() as $site) {
                $sites[] = $site->getId();
            }
            $ocr_url_list = [];
            $media_data = $media->getData();
            foreach ($media_data['components'] as $component) {
                if ($component['ocr']) {
                    $ocr_url_list[] = $component['ocr'];
                }
            }
            if ($ocr_url_list) {
                $joined_mini_ocr_output = "";
                foreach ($ocr_url_list as $ocr_url) {
                    $logger->info("Downloading: $ocr_url");
                    $parsed_url = parse_url($ocr_url);
                    $subdomains = explode(".", $parsed_url["host"]);
                    if ((array_key_exists(0, $subdomains)) && (array_key_exists(2, $subdomains))) {
                        $key = ltrim($parsed_url["path"], '/');
                        $bucket = $subdomains[0];
                        $region = $subdomains[2];
                        if ($key && $bucket && $region) {
                            $file = $s3Client->getObject([
                                'Bucket' => $bucket,
                                '@region' => $region,
                                'Key' => $key,
                            ]);
                            $xml_file = $file->get('Body');
                            $logger->info("Converting file.");
                            $mini_ocr_output = $this->convertAltoToMiniocr($xml_file, $logger);
                            $joined_mini_ocr_output .= $mini_ocr_output;
                        }
                    }
                }
                if ($joined_mini_ocr_output) {
                    $logger->info("Sending to index.");
                    $doc = new SolrInputDocument();
                    $doc->addField('media_id', $mediaId);
                    $doc->addField('item_id', $itemId);
                    foreach ($itemSetsIds as $itemSetsId) {
                        $doc->addField('item_set_ids', $itemSetsId);
                    }
                    $doc->addField('is_public', $isPublic);
                    foreach ($groups as $group) {
                        $doc->addField('groups', $group);
                    }
                    foreach ($sites as $site) {
                        $doc->addField('sites', $site);
                    }
                    $doc->addField('ocr_text', $joined_mini_ocr_output);
                    $solrClient->addDocument($doc);
                    // May not need to commit after each ingest if auto-commit is set in the index
                    // $solrClient->commit();

                    $media_data['indexed'] = 1;
                    $media->setData($media_data);
                    $em->flush();
                    $logger->info("Updated index status for media: " . $mediaId);
                }
            }
        }
    }

    private function convertAltoToMiniocr($xml_file)
    {
        // --- Detect format ---
        $head = substr($xml_file, 0, 512);
        $isAlto = stripos($head, '<alto') !== false;

        // --- Parse events ---
        if ($isAlto) {
            $events = $this->parse_alto($xml_file);
            return $this->generate_miniocr($events);
        }
        return "";
    }

    private function parse_alto($alto)
    {
        $events = [];
        $useRelative = false;
        $relativeReference = null;
        $curWord = null;

        // Load as XML
        $reader = new XMLReader();
        $reader->XML($alto);
        $depth = 0;

        while ($reader->read()) {
            $nodeType = $reader->nodeType;
            $tagName = $reader->localName;

            if ($nodeType === XMLReader::ELEMENT) {
                $depth++;
                if ($tagName === "SP") {
                    $ev = new ParseEvent();
                    $ev->setKind(EventKind::TEXT);
                    $ev->setText(" ");
                    $events[] = $ev;
                }

                if ($tagName === "MeasurementUnit" && $reader->readInnerXML() !== "pixel") {
                    $useRelative = true;
                }

                if ($tagName === "ALTERNATIVE" && $curWord !== null) {
                    $reader->read(); // Move to text node
                    $curWord->setText($curWord->getText() . "⇿" . $reader->value);
                }

                $boxType = BoxType::fromAltoTag($tagName);
                if ($boxType === null) {
                    continue;
                }

                $ev = new ParseEvent();
                $ev->setKind(EventKind::START);
                $ev->setBoxType($boxType);

                if ($tagName === "Page") {
                    if ($reader->getAttribute("ID")) {
                        $ev->setPageId($reader->getAttribute("ID"));
                    }
                    if ($useRelative) {
                        $relativeReference = [
                            (int)$reader->getAttribute("WIDTH"),
                            (int)$reader->getAttribute("HEIGHT")
                        ];
                    }
                }

                $hasCoords = $reader->getAttribute("HPOS") &&
                    $reader->getAttribute("VPOS") &&
                    $reader->getAttribute("WIDTH") &&
                    $reader->getAttribute("HEIGHT");

                if ($hasCoords) {
                    $ev->setX((float)$reader->getAttribute("HPOS"));
                    $ev->setY((float)$reader->getAttribute("VPOS"));
                    $ev->setWidth((float)$reader->getAttribute("WIDTH"));
                    $ev->setHeight((float)$reader->getAttribute("HEIGHT"));

                    if ($useRelative && $relativeReference) {
                        [$rw, $rh] = $relativeReference;
                        $ev->setX($ev->getX() / $rw);
                        $ev->setY($ev->getY() / $rh);
                        $ev->setWidth($ev->getWidth() / $rw);
                        $ev->setHeight($ev->getHeight() / $rh);
                    } else {
                        $ev->setX((int)$ev->getX());
                        $ev->setY((int)$ev->getY());
                        $ev->setWidth((int)$ev->getWidth());
                        $ev->setHeight((int)$ev->getHeight());
                    }
                }

                if ($boxType === BoxType::WORD) {
                    $ev->setText($reader->getAttribute("CONTENT"));
                    $subsType = $reader->getAttribute("SUBS_TYPE");
                    if ($subsType === "HypPart1") {
                        $ev->setText($ev->getText() . "\xad");
                    }
                    if (!$reader->isEmptyElement) {
                        $curWord = $ev;
                        continue; // Wait to yield until END
                    }
                }

                $events[] = $ev;
                // Empty elements does give an END_Element tag so much be close here.
                if ($reader->isEmptyElement) {
                    $ev = new ParseEvent();
                    $ev->setKind(EventKind::END);
                    $ev->setBoxType($boxType);
                    $events[] = $ev;
                }
            } elseif ($nodeType === XMLReader::END_ELEMENT) {
                $boxType = BoxType::fromAltoTag($tagName);
                if ($boxType === null) {
                    continue;
                }

                if ($boxType === BoxType::WORD && $curWord !== null) {
                    $events[] = $curWord;
                    $curWord = null;
                }

                $ev = new ParseEvent();
                $ev->setKind(EventKind::END);
                $ev->setBoxType($boxType);
                $events[] = $ev;
                $depth--;
            }
        }

        return $events;
    }

    private function generate_miniocr(array $events): string
    {
        $output = ["<ocr>"];
        $lastHyphen = false;

        foreach ($events as $evt) {
            if ($evt->getKind() === EventKind::TEXT && $evt->getText() !== null) {
                if ($lastHyphen && trim($evt->getText()) === "") {
                    continue;
                }
                $output[] = $evt->getText();
                $lastHyphen = mb_substr($evt->getText(), -1) === "\xad";
                continue;
            }

            if ($evt->getBoxType() === null) {
                continue;
            }

            $tag = BoxType::toMiniocrTag($evt->getBoxType());

            if ($evt->getKind() === EventKind::START) {
                $attribs = [];

                if ($evt->getBoxType() === BoxType::PAGE) {
                    if ($evt->getPageId()) {
                        $attribs[] = 'xml:id="' . htmlspecialchars($evt->getPageId(), ENT_QUOTES) . '"';
                    }
                    if (is_int($evt->getWidth()) && is_int($evt->getHeight())) {
                        $attribs[] = 'wh="' . $evt->getWidth() . ' ' . $evt->getHeight() . '"';
                    }
                } elseif ($evt->getBoxType() === BoxType::WORD) {
                    if ($evt->getX() && $evt->getY() && $evt->getWidth() && $evt->getHeight()) {
                        if (is_float($evt->getX()) || is_float($evt->getY())) {
                            $coords = implode(" ", array_map(fn($f) => substr(sprintf("%.4f", $f), 1), [
                                $evt->getX(),
                                $evt->getY(),
                                $evt->getWidth(),
                                $evt->getHeight()
                            ]));
                            $attribs[] = 'x=' . $coords;
                        } else {
                            $attribs[] = 'x="' . implode(" ", [$evt->getX(), $evt->getY(), $evt->getWidth(), $evt->getHeight()]) . '"';
                        }
                    }
                }

                $line = '<' . $tag;
                if (!empty($attribs)) {
                    $line .= ' ' . implode(" ", $attribs);
                }
                $line .= '>';
                $output[] = $line;

                if ($evt->getBoxType() === BoxType::WORD && $evt->getText() !== null) {
                    $lastHyphen = mb_substr($evt->getText(), -1) === "\xad";
                    $output[] = htmlspecialchars($evt->getText(), ENT_NOQUOTES | ENT_SUBSTITUTE);
                }
            } elseif ($evt->getKind() === EventKind::END) {
                $output[] = "</$tag>";
                if ($evt->getBoxType() === BoxType::LINE && !$lastHyphen) {
                    $output[] = " ";
                }
            }
        }

        $output[] = "</ocr>";
        return implode("", $output);
    }
}
