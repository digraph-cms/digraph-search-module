<?php
/* Digraph Core | https://gitlab.com/byjoby/digraph-core | MIT License */
namespace Digraph\Modules\Search;

use Digraph\DSO\Noun;
use Digraph\Helpers\AbstractHelper;
use TeamTNT\TNTSearch\TNTSearch;

class SearchHelper extends AbstractHelper
{
    protected $tnt;
    protected $indexer;
    protected $transaction = 0;

    public function initialize()
    {
        //set up search object
        $this->tnt = new TNTSearch;
        $this->tnt->loadConfig([
            'driver' => 'filesystem',
            'storage' => $this->cms->config['paths.storage']
        ]);
        $this->tnt->fuzziness = true;
        //set up hooks to index nouns on insert/update/delete
        $hooks = $this->cms->helper('hooks');
        $hooks->noun_register('update', [$this,'queueIndex'], 'search/index');
        $hooks->noun_register('insert', [$this,'queueIndex'], 'search/index');
        $hooks->noun_register('delete', [$this,'queueDelete'], 'search/delete');
        $hooks->noun_register('delete_permanent', [$this,'queueDelete'], 'search/delete');
        //hooks to index parents/children as well
        $hooks->noun_register('parent:update', [$this,'queueIndex'], 'search/index');
        $hooks->noun_register('child:update', [$this,'queueIndex'], 'search/index');
        $hooks->noun_register('parent:insert', [$this,'queueIndex'], 'search/index');
        $hooks->noun_register('child:insert', [$this,'queueIndex'], 'search/index');
        $hooks->noun_register('parent:delete', [$this,'queueIndex'], 'search/index');
        $hooks->noun_register('child:delete', [$this,'queueIndex'], 'search/index');
    }

    public function hook_cron()
    {
        $count = 0;
        $errors = [];
        $queue = $this->cms->helper('datastore')->queue('search_indexing');
        $this->beginTransaction();
        while (($fact = $queue->pull1()) && $count < 10) {
            if ($fact['action'] == 'delete') {
                $count++;
                $this->delete($fact['noun']);
            } elseif ($noun = $this->cms->read($fact['noun'])) {
                $count++;
                $this->index($noun);
            } else {
                $errors[] = 'couldn\'t index '.$fact['noun'];
            }
        }
        $this->endTransaction();
        return [
            'result' => count($pruned),
            'errors' => $errors
        ];
    }

    public function queueIndex($noun)
    {
        $noun = $this->sanitizeNoun($noun);
        $q = $this->cms->helper('datastore')->queue('search_indexing');
        $q->unique(true);
        $q->put([
            'noun' => $noun,
            'action' => 'index'
        ]);
    }

    public function queueDelete($noun)
    {
        $noun = $this->sanitizeNoun($noun);
        $q = $this->cms->helper('datastore')->queue('search_indexing');
        $q->unique(true);
        $q->put([
            'noun' => $noun,
            'action' => 'delete'
        ]);
    }

    protected function sanitizeNoun($noun)
    {
        if (!$noun) {
            return '';
        }
        if ($noun instanceof Noun) {
            return $noun['dso.id'];
        }
        $noun = strtolower($noun);
        $noun = preg_replace('/[^a-z0-9]/', '', $noun);
        return $noun;
    }

    public function form()
    {
        $form = new \Formward\Fields\Container('', 'search');
        $form->tag = 'form';
        $form->addClass('Form');
        $form->addClass('search-form');
        $form->attr('action', $this->cms->helper('urls')->url('_search', 'display'));
        $form->method('get');
        $form['q'] = new \Formward\Fields\Input('');
        $form['submit'] = new \Formward\SystemFields\Submit('Search');
        return $form;
    }

    public function search($query)
    {
        $this->indexer();
        $result = $this->tnt->search($query)['ids'];
        //direct-read
        foreach ($this->cms->locate(trim($query)) as $d) {
            $result[] = $d['dso.id'];
            $result = array_unique($result);
        }
        //filter before returning
        $result = array_filter($result);
        return $result;
    }

    public function highlights($query, $noun)
    {
        $text = strip_tags(
            $this->article($noun),
            [
                'ignore_errors' => true,
                'drop_links' => true
            ]
        );
        $text = $this->tnt->highlight($text, $query);
        $length = $this->cms->config['search.highlight.length'];
        $positions = [];
        while (($lastPos = strpos($text, '<em>', @$lastPos))!== false) {
            $positions[] = $lastPos;
            $lastPos = $lastPos + strlen('<em>');
        }
        $highlights = [];
        $limit = -1;
        foreach ($positions as $pos) {
            if ($pos <= $limit) {
                continue;
            }
            $hl = substr($text, $pos, $length);
            $limit = $pos + $length;
            $highlights[] = $this->tnt->highlight(htmlentities(strip_tags($hl)), $query);
        }
        return array_slice($highlights, 0, $this->cms->config['search.highlight.count']);
    }

    public function shouldBeIndexed($noun)
    {
        if (method_exists($noun, 'searchIndexed')) {
            return $noun->searchIndexed();
        }
        return true;
    }

    protected function &indexer()
    {
        if (!$this->indexer) {
            try {
                $this->tnt->selectIndex('digraph.index');
            } catch (\Exception $e) {
                $this->tnt->createIndex('digraph.index');
                $this->tnt->selectIndex('digraph.index');
            }
            $this->indexer = $this->tnt->getIndex();
        }
        return $this->indexer;
    }

    public function delete($noun)
    {
        $noun = $this->sanitizeNoun($noun);
        $this->beginTransaction();
        $this->indexer()->delete($noun);
        $this->endTransaction();
    }

    public function beginTransaction()
    {
        if ($this->transaction == 0) {
            $this->indexer()->indexBeginTransaction();
            $this->transaction++;
        }
    }

    public function endTransaction()
    {
        if ($this->transaction > 0) {
            $this->indexer()->indexEndTransaction();
            $this->transaction--;
        }
    }

    protected function article($noun)
    {
        $cache = $this->cms->cache();
        $cacheID = md5(static::class).'.article.'.$noun['dso.id'];
        $article = $cache->getItem($cacheID);
        if (!$article->isHit()) {
            $article->set($this->doArticle($noun));
            $cache->save($article);
        }
        return $article->get();
    }

    protected function doArticle($noun)
    {
        $article = [
            $noun->url()['noun'],
            $noun->url()['canonicalnoun'],
            $noun->name(),
            $noun->title(),
            $noun->body()
        ];
        //get additional search text
        if (method_exists($noun, 'additionalSearchText')) {
            $article[] = $noun->additionalSearchText();
        }
        //index attached filestore files
        $article[] = $this->indexFSFiles($noun);
        //get additional searchable text from helpers
        foreach ($this->cms->allHelpers() as $name) {
            if (method_exists($this->cms->helper($name), 'hook_search_index')) {
                $article[] = $this->cms->helper($name)->hook_search_index($noun);
            }
        }
        //return as single string with back-to-back repeated chunks deduplicated
        $article = implode(' ', $article);
        $article = preg_replace('/ (.+)( +\1)+/i', '$1', $article);
        return $article;
    }

    public function indexFSFiles(&$noun)
    {
        //try to allocate way more memory
        ini_set('memory_limit', '500M');
        $memory_limit = return_bytes(ini_get('memory_limit'));
        $out = '';
        foreach ($this->cms->helper('filestore')->allFiles($noun) as $file) {
            //add file metacard data to search index text
            $out .= ' '.$file->metaCard();
            //if file is a PDF, extract its text and put that in the index text
            if ($file->type() == 'application/pdf') {
                if ($file->size() > $memory_limit/10) {
                    //don't try to parse anything additional from files over 1/10 the memory_limit
                    continue;
                }
                try {
                    $parser = new \Smalot\PdfParser\Parser();
                    $pdf = @$parser->parseFile($file->path());
                    $out .= ' '.@$pdf->getText();
                    unset($pdf);
                    unset($parser);
                } catch (\Exception $e) {
                    ob_end_clean();//need this until probably the next version of pdfparser
                    $out .= '';
                }
            }
        }
        return $out;
    }

    public function index($noun)
    {
        //verify that this noun should be indexed, remove it if it's deleted or
        //should not be indexed
        if ($noun['dso.deleted'] || !$this->shouldBeIndexed($noun)) {
            $this->delete($noun);
            return;
        }
        //get content for item
        $data = [
            'id' => $noun['dso.id'],
            'title' => $noun->title(),
            'article' => $this->article($noun)
        ];
        //get search override title
        if (method_exists($noun, 'searchResultTitle')) {
            $data['title'] = $noun->searchResultTitle();
        }
        //insert into index
        $this->beginTransaction();
        $this->indexer()->update(
            $noun['dso.id'],
            $data
        );
        $this->endTransaction();
    }
}

function return_bytes($val)
{
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $val = substr($val,0,strlen($val)-1);
    switch ($last) {
        // The 'G' modifier is available since PHP 5.1.0
        case 'g':
            $val *= 1024;
            // no break
        case 'm':
            $val *= 1024;
            // no break
        case 'k':
            $val *= 1024;
    }
    return $val;
}
