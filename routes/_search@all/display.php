<?php
$package->noCache();
$search = $cms->helper('search');
$t = $cms->helper('templates');

$form = $search->form();
echo $form;

if ($query = $package['url.args.search_q']) {
    if ($results = $search->search($query)) {
        echo $cms->helper('paginator')->paginate(
            $results, //things to paginate
            $package, //package (to get url/arguments from)
            'page', //argument to use for page
            $cms->config['search.perpage'], //items per page
            function ($e) use ($query, $t, $cms, $search) { //callback given elements
                if ($noun = $cms->read($e)) {
                    return $t->render('digraph/search-result.twig', ['result' => [
                        'noun' => $noun,
                        'highlights' => $search->highlights($query, $noun),
                    ]]);
                }
                return '';
            }
        );
    } else {
        echo "<div class='notification notification-notice'>No results</div>";
    }
}
