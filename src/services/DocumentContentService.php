<?php
/**
 * Document Search plugin for Craft CMS 3.x
 *
 * Extract the contents of text documents and add to Craft's search index
 *
 * @link      https://venveo.com
 * @copyright Copyright (c) 2019 Venveo
 */

namespace venveo\documentsearch\services;

use Craft;
use craft\base\Component;
use craft\base\Volume;
use craft\elements\Asset;
use craft\helpers\Db;
use Spatie\PdfToText\Pdf;
use venveo\documentsearch\DocumentSearch as Plugin;
use venveo\documentsearch\models\Settings;
use voku\helper\StopWordsLanguageNotExists;
use yii\db\Schema;

/**
 * @author    Venveo
 * @package   DocumentSearch
 * @since     1.0.0
 */
class DocumentContentService extends Component
{
    /**
     * @param Asset $asset
     * @return string|null
     * @throws \yii\base\InvalidConfigException
     */
    public function getAssetContentKeywords(Asset $asset): ?string
    {
        /** @var Settings $settings */
        $settings = Plugin::$plugin->getSettings();

        // check to make sure the volume is allowed to be indexed
        /** @var Volume $volume */
        $volume = $asset->getVolume();
        if (!in_array($volume->id, $settings->indexVolumes, true)) {
            return null;
        }

        // make sure the asset size doesn't exceed our maximum
        if ($asset->size > $settings->maximumDocumentSize * 1024) {
            Craft::info('Skipping asset ('.$asset->id.') because it exceeds maximumDocumentSize ('.$settings->maximumDocumentSize.')', __METHOD__);
            return null;
        }

        // We're only dealing with PDFs right now
        if ($asset->kind == Asset::KIND_PDF) {
            $text = $this->extractContentFromPDF($asset->getCopyOfFile());
        }

        // If we have text, let's extract the keywords from it
        if (isset($text)) {
            // Try to figure out what language the document is in
            $language = $asset->getSite()->language ?: 'en';
            $languageParts = explode('-', $language);
            $languageShort = strtolower(array_shift($languageParts));

            // If we can - let's just store the entire text
            $db = Craft::$app->getDb();
            if ($isPgsql = $db->getIsPgsql()) {
                $maxSize = Craft::$app->search->maxPostgresKeywordLength;
            } else {
                $maxSize = Db::getTextualColumnStorageCapacity(Schema::TYPE_TEXT);
            }
            if (mb_strlen($text) < $maxSize) {
                return $text;
            }

            $scoredKeywords_1 = Plugin::$plugin->rake->get($text, 1, $languageShort);
            $scoredKeywords_2 = Plugin::$plugin->rake->get($text, 2, $languageShort);
            $scoredKeywords_3 = Plugin::$plugin->rake->get($text, 3, $languageShort);
            $count = count($scoredKeywords_1) + count($scoredKeywords_2) + count($scoredKeywords_3);

            // If there are more than 100 keywords, let's just get the first third
            if ($count > 100) {
                $scoredKeywords = array_slice($scoredKeywords_1, 0, 30) +
                    array_slice($scoredKeywords_2, 0, 30) +
                    array_slice($scoredKeywords_3, 0, 30);
            } else {
                $scoredKeywords = $scoredKeywords_1 + $scoredKeywords_2 + $scoredKeywords_3;
            }

            // Assemble the keywords into a string
            $results = implode(' ', array_keys($scoredKeywords));
            Craft::info('Extracted '.count($scoredKeywords).' keywords from: '.$asset->id.' in '.$languageShort, __METHOD__);
        } else {
            Craft::info('No text found in '.$asset->id, __METHOD__);
            return null;
        }
        return $results;
    }


    /**
     * Gets the textual content from a PDF
     *
     * @param string $filepath
     * @return string
     */
    public function extractContentFromPDF($filepath): string
    {
        Craft::info('Extracting PDF content from: '.$filepath, __METHOD__);
        // change directory to guarantee writable directory
        chdir(Craft::$app->path->getAssetsPath().DIRECTORY_SEPARATOR);
        return Pdf::getText($filepath, Craft::parseEnv(Plugin::$plugin->getSettings()->pdfToTextExecutable));
    }
}
