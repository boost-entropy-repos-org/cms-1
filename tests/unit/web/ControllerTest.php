<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace crafttests\unit\web;

use Codeception\Test\Unit;
use Craft;
use craft\helpers\UrlHelper;
use craft\test\mockclasses\controllers\TestController;
use craft\web\Response;
use craft\web\View;
use UnitTester;
use yii\base\Action;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\InvalidRouteException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

/**
 * Unit tests for Controller
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @author Global Network Group | Giel Tettelaar <giel@yellowflash.net>
 * @since 3.2
 */
class ControllerTest extends Unit
{
    /**
     * @var UnitTester
     */
    protected $tester;

    /**
     * @var TestController
     */
    private $controller;

    /**
     *
     */
    public function testBeforeAction()
    {
        Craft::$app->getConfig()->getGeneral()->isSystemLive = true;

        $this->tester->expectThrowable(ForbiddenHttpException::class, function() {
            // AllowAnonymous should redirect and Craft::$app->exit(); I.E. An exit exception
            $this->controller->beforeAction(new Action('not-allow-anonymous', $this->controller));
        });

        self::assertTrue($this->controller->beforeAction(new Action('allow-anonymous', $this->controller)));
    }

    /**
     * @throws InvalidRouteException
     */
    public function testRunActionJsonError()
    {
        // We accept JSON.
        Craft::$app->getRequest()->setAcceptableContentTypes(['application/json' => true]);
        Craft::$app->getRequest()->headers->set('Accept', 'application/json');

        /* @var Response $resp */
        $resp = $this->controller->runAction('me-dont-exist');

        // As long as this is set. We can expect yii to do its thing.
        self::assertSame(Response::FORMAT_JSON, $resp->format);
    }

    /**
     * @throws Exception
     */
    public function testTemplateRendering()
    {
        // We need to render a template from the site dir.
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_SITE);

        $response = $this->controller->renderTemplate('template');

        // Again. If this is all good. We can expect Yii to do its thing.
        self::assertSame('Im a template!', $response->data);
        self::assertSame(Response::FORMAT_RAW, $response->format);
        self::assertSame('text/html; charset=UTF-8', $response->getHeaders()->get('content-type'));
    }

    /**
     * If the content-type headers are already set. Render Template should ignore attempting to set them.
     *
     * @throws Exception
     */
    public function testTemplateRenderingIfHeadersAlreadySet()
    {
        // We need to render a template from the site dir.
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_SITE);
        Craft::$app->getResponse()->getHeaders()->set('content-type', 'HEADERS');

        $response = $this->controller->renderTemplate('template');

        // Again. If this is all good. We can expect Yii to do its thing.
        self::assertSame('Im a template!', $response->data);
        self::assertSame(Response::FORMAT_RAW, $response->format);
        self::assertSame('HEADERS', $response->getHeaders()->get('content-type'));
    }

    /**
     * @throws Exception
     * @throws InvalidConfigException
     * @throws BadRequestHttpException
     */
    public function testRedirectToPostedUrl()
    {
        $baseUrl = $this->_getBaseUrlForRedirect();
        $redirect = Craft::$app->getSecurity()->hashData('craft/do/stuff');

        // Default
        $default = $this->controller->redirectToPostedUrl();

        // Test that with nothing passed in. It defaults to the base. See self::getBaseUrlForRedirect() for more info.
        self::assertSame(
            $baseUrl,
            $default->headers->get('Location')
        );

        // What happens when we pass in a param.
        Craft::$app->getRequest()->setBodyParams(['redirect' => $redirect]);
        $default = $this->controller->redirectToPostedUrl();
        self::assertSame($baseUrl . '?' . urldecode(http_build_query(['p' => 'craft/do/stuff'])), $default->headers->get('Location'));
    }

    /**
     * @throws BadRequestHttpException
     */
    public function testRedirectToPostedWithSetDefault()
    {
        $baseUrl = $this->_getBaseUrlForRedirect();
        $withDefault = $this->controller->redirectToPostedUrl(null, 'craft/do/stuff');
        self::assertSame($baseUrl . '?' . urldecode(http_build_query(['p' => 'craft/do/stuff'])), $withDefault->headers->get('Location'));
    }

    /**
     *
     */
    public function testAsJsonP()
    {
        $result = $this->controller->asJsonP(['test' => 'test']);
        self::assertSame(Response::FORMAT_JSONP, $result->format);
        self::assertSame(['test' => 'test'], $result->data);
    }

    /**
     *
     */
    public function testAsRaw()
    {
        $result = $this->controller->asRaw(['test' => 'test']);
        self::assertSame(Response::FORMAT_RAW, $result->format);
        self::assertSame(['test' => 'test'], $result->data);
    }

    /**
     *
     */
    public function testAsErrorJson()
    {
        $result = $this->controller->asErrorJson('im an error');
        self::assertSame(Response::FORMAT_JSON, $result->format);
        self::assertSame(['error' => 'im an error'], $result->data);
    }

    /**
     *
     */
    public function testRedirect()
    {
        self::assertSame(
            $this->_getBaseUrlForRedirect() . '?' . urldecode(http_build_query(['p' => 'do/stuff'])),
            $this->controller->redirect('do/stuff')->headers->get('Location')
        );

        // We dont use _getBaseUrlForRedirect because the :port80 wont work with urlWithScheme.
        self::assertSame(
            'https://test.craftcms.test:80/index.php',
            $this->controller->redirect(null)->headers->get('Location')
        );

        // Absolute url
        self::assertSame(
            'https://craftcms.com',
            $this->controller->redirect('https://craftcms.com')->headers->get('Location')
        );

        // Custom status code
        self::assertSame(
            500,
            $this->controller->redirect('https://craftcms.com', 500)->statusCode
        );
    }

    /**
     * @inheritdoc
     */
    protected function _before()
    {
        parent::_before();
        $_SERVER['REQUEST_URI'] = 'https://craftcms.com/admin/dashboard';
        $this->controller = new TestController('test', Craft::$app);
    }

    private function _determineUrlScheme(): string
    {
        return !Craft::$app->getRequest()->getIsConsoleRequest() && Craft::$app->getRequest()->getIsSecureConnection() ? 'https' : 'http';
    }

    private function _getBaseUrlForRedirect(): string
    {
        $scheme = $this->_determineUrlScheme();
        return UrlHelper::urlWithScheme(Craft::$app->getConfig()->getGeneral()->siteUrl, $scheme);
    }
}
