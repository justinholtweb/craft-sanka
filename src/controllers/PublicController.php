<?php

declare(strict_types=1);

namespace justinholtweb\sanka\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\sanka\models\Edition;
use justinholtweb\sanka\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The four files Sanka serves to the outside world.
 *
 * Routes rather than files on disk, so nothing has to be deployed, nothing goes stale when a key is
 * regenerated, and turning a feature off actually removes the file rather than leaving a copy of it
 * lying in the web root.
 */
class PublicController extends Controller
{
    public $defaultAction = 'key';

    protected array|bool|int $allowAnonymous = true;

    /**
     * The IndexNow key file.
     *
     * The whole protocol rests on this being fetchable: an engine reads it to confirm that whoever
     * submitted the URLs controls the host. It is plain text containing exactly the key and nothing
     * else — a trailing newline is fine, anything more is not.
     */
    public function actionKey(): Response
    {
        $settings = Plugin::getInstance()->getSettings();
        $key = trim($settings->indexNowKey);

        if (!$settings->indexNowEnabled || $key === '') {
            throw new ForbiddenHttpException('IndexNow is not enabled.');
        }

        return $this->text($key);
    }

    public function actionLlms(): Response
    {
        $this->requireGeo();

        if (!Plugin::getInstance()->getSettings()->llmsEnabled) {
            throw new ForbiddenHttpException('llms.txt is not enabled.');
        }

        return $this->text(Plugin::getInstance()->llms->map(Craft::$app->getSites()->getCurrentSite()->id));
    }

    public function actionLlmsFull(): Response
    {
        $this->requireGeo();
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->llmsEnabled || !$settings->llmsFullEnabled) {
            throw new ForbiddenHttpException('llms-full.txt is not enabled.');
        }

        return $this->text(Plugin::getInstance()->llms->full(Craft::$app->getSites()->getCurrentSite()->id));
    }

    public function actionRobots(): Response
    {
        $this->requireGeo();

        if (!Plugin::getInstance()->getSettings()->robotsEnabled) {
            throw new ForbiddenHttpException('Sanka is not serving robots.txt.');
        }

        return $this->text(Plugin::getInstance()->crawlers->robotsTxt(Craft::$app->getSites()->getCurrentSite()->id));
    }

    // ----------------------------------------------------------------- private

    private function requireGeo(): void
    {
        if (!Edition::allowsGeo(Plugin::getInstance()->isPro())) {
            throw new ForbiddenHttpException('This is a Pro feature.');
        }
    }

    /**
     * `text/plain; charset=utf-8` and nothing else.
     *
     * Set on the response rather than left to Craft: these are consumed by parsers that are strict
     * about the content type, and a `.txt` route inside Craft would otherwise inherit whatever the
     * site's default happens to be.
     */
    private function text(string $body): Response
    {
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'text/plain; charset=utf-8');
        $response->data = $body;

        return $response;
    }
}
