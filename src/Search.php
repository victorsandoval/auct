<?php
namespace YahooAuctions;

use GuzzleHttp\Client;
use Rct567\DomQuery\DomQuery;
use Sunra\PhpSimple\HtmlDomParser;
use Symfony\Component\DomCrawler\Crawler;
use Wa72\HtmlPageDom\HtmlPage;
use function GuzzleHttp\Psr7\str;
use function simplehtmldom_1_5\str_get_html;

class Search
{
    /**
     * @var string URL TO SEARCH
     */
    private $url = 'https://auctions.yahoo.co.jp/search/search';

    /**
     * @var string Query string to search in auctions
     */
    private $query = '';

    private $client = null;
    private $replace = '';


    public function __construct($replace)
    {

        $this->replace = $replace;
        $options = [
            //'cookies' => true,
            'force_ip_resolve' => 'v4',
            //'verify' => false
        ];

        $client = new Client($options);
        $this->client = $client;

    }

    function get_attribute_contents($element)
    {
        $obj_attribute = array();
        foreach ($element->attributes as $attribute) {
            $obj_attribute[$attribute->name] = $attribute->value;
        }
        return $obj_attribute;
    }

//Function to get contents of a child element of an HTML tag
    function get_child_contents($element)
    {
        $obj_child = array();
        foreach ($element->childNodes as $subElement) {
            if ($subElement->nodeType != XML_ELEMENT_NODE) {
                if(trim($subElement->wholeText) != "")
                {
                    $obj_child["value"] = $subElement->wholeText;
                }
            }
            else {
                if($subElement->getAttribute('id'))
                {
                    $obj_child[$subElement->tagName."#".$subElement->getAttribute('id')] = $this->get_tag_contents($subElement);
                }
                else
                {
                    $obj_child[$subElement->tagName] = $this->get_tag_contents($subElement);
                }
            }
        }
        return $obj_child;
    }

//Function to get the contents of an HTML tag
    function get_tag_contents($element)
    {
        $obj_tag = array();
        if($this->get_attribute_contents($element))
        {
            $obj_tag["attributes"] = $this->get_attribute_contents($element);
        }
        if($this->get_child_contents($element))
        {
            $obj_tag["child_nodes"]= $this->get_child_contents($element);
        }

        return $obj_tag;
    }

//Function to convert a DOM element to an object
    function element_to_obj($element) {
        $object = array();
        $tag = $element->tagName;
        $object[$tag] = $this->get_tag_contents($element);
        return $object;
    }

//Function to convert an HTML to a DOM element
    function html_to_obj($html) {
        $dom = new \DOMDocument();
        //dump($html);
        $dom->loadHTML($html);
        //$docElement=$dom->documentElement;
        //return $this->element_to_obj($dom->documentElement);
        return '';
    }


    public function search($query)
    {
        $result = $this->client->get($query);


        $html = $result->getBody()->getContents();

        $dom = new DomQuery($html);


        $return = new \stdClass();
        $return->items = $this->getProducts($dom);
        $return->categories = $this->getCategories($dom);
        $return->categoryTree = $this->getCategoryTree($dom);
        $return->itemStatus = $this->getStatus($dom);
        $return->pager = $this->getPager($dom);

        return $return;
    }

    public function generateURL($url)
    {
        $url = str_replace('https://auctions.yahoo.co.jp/search/search', $this->replace, $url);

        return $url;
    }

    private function getProducts($dom)
    {
        $productos = $dom->find('.Result__body li.Product');
        $pr = [];

        foreach ($productos as $node){

            $producto = new \stdClass();
            $producto->image = $node->children('.Product__image')->children('a')->children('img')->attr('src');

            $details = $node->children('.Product__detail')->children('.Product__title')
                ->children('.Product__titleLink');

            $producto->name = trim($details->attr('title'));

            $datos = explode(';', $details->attr('data-ylk'));

            foreach ($datos as $strVal) {
                list($k, $v) = explode(':', $strVal);
                $producto->$k = $v;
            }

            $datos = explode(',', $producto->etc);

            foreach ($datos as $strVal) {
                list($k, $v) = explode('=', $strVal);
                $producto->$k = $v;
            }
            $pr[] = $producto;
        }

        return $pr;
    }

    public function getPager(DomQuery $dom)
    {
        $pager = new \stdClass();
        $items = $dom->find('.Pager > .Pager__lists > li');

        $elements = [];

        foreach ($items as $item) {
            $element = new \stdClass();

            $name = $item->children()->text();

            $name = str_replace('前へ', '<', $name);
            $name = str_replace('次へ', '>', $name);

            if ($item->children()->hasClass('Pager__link--active')) {
                $element->active = true;
            }else{
                $element->active = false;
            }

            $element->name =$name;
            $element->href = $this->generateURL($item->children()->attr('href'), $this->replace);

            $elements[] = $element;
        }

        return $elements;

    }

    public function getCategories(DomQuery $dom)
    {
        $items = $dom->find(".Filter[data-name='category'] > .Filter__body > .Filter__items > li");//
        $categories = [];

        foreach($items as $item) {

            $category = new \stdClass();

            $category->name = $this->getName($item->children()->text());
            $category->href =  $this->generateURL($item->children('a')->attr('href'));
            $category->items = $this->getItems($item->children()->text());

            $children = [];

            if (count($item->children()) == 3) {

                foreach ($item->find('.Filter__items > li') as $item1) {

                    if (!$item1->hasClass('Filter__item--carBody')) {
                        $category1 = new \stdClass();
                        $category1->name = $this->getName($item1->children()->text());
                        $category1->href = $this->generateURL($item1->children('a')->attr('href'));
                        $category1->items = $this->getItems($item1->children()->text());
                        $children[] = $category1;
                    }
                }
            }

            $category->children = $children;

            $categories[] = $category;
        }

        return $categories;
    }

    public function getCategoryTree($dom)
    {
        $items = $dom->find(".CategoryTree__items > li");//
        $categories = [];

        foreach($items as $item) {

            $category = new \stdClass();
            $category->name = $item->children()->text();
            $category->href = $this->generateURL($item->children()->attr('href'));

            $categories[] = $category;

        }

        return $categories;

    }

    public function getStatus($dom)
    {

        $items = $dom->find(".Filter[data-name='itemstatus'] > .Filter__body > .Filter__items > li > .CheckBox");//
        $categories = [];

        foreach($items as $item) {

            $category = new \stdClass();
            $category->name = $item->children('span')->text();
            $category->href = $this->generateURL($item->children('input')->attr('data-url'));

            $categories[] = $category;
        }

        return $categories;
    }

    public function getName($text){
        $value = explode('(', $text);
        return $value[0];
    }

    /**
     * @return string
     */
    public function getItems($text)
    {
        $text = str_replace(',', '', $text);
        $value = explode('(', $text);

        if (isset($value[1])) {
            return intval(str_replace(')', '', $value[1]));
        } else {
            return 0;
        }

    }

}