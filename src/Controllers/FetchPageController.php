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
        $agent = "Mozilla/5.0 (X11; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/111.0";
        $chromeOptions = new ChromeOptions();
        $chromeOptions->addArguments(['--headless']);
        $chromeOptions->addArguments(array(
            '--user-agent=' . $agent
        ));
        $capabilities = \Facebook\WebDriver\Remote\DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY_W3C, $chromeOptions);
        $driver = ChromeDriver::start($capabilities);

        $driver->get($url);
        $text = $driver->findElement(WebDriverBy::tagName('body'))->getText();

        $driver->quit();

        return $text;
    }
}
