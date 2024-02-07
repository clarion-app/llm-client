<?php

namespace ClarionApp\LlmClient\Controllers;

use Illuminate\Http\Request;
use Facebook\WebDriver\Chrome\ChromeDriver;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\WebDriverBy;
use App\Http\Controllers\Controller;

class FetchPageController extends Controller
{
    public function getTextFromUrl(Request $request)
    {
        $url = $request->input('url');

        $host = 'http://localhost:9515'; // ChromeDriver default URL
        $chromeOptions = new ChromeOptions();
        $chromeOptions->addArguments(['--headless']);
        $capabilities = \Facebook\WebDriver\Remote\DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY_W3C, $chromeOptions);
        $driver = ChromeDriver::start($capabilities);

        $driver->get($url);
        $text = $driver->findElement(WebDriverBy::tagName('body'))->getText();

        $driver->quit();

        return $text;
    }
}
