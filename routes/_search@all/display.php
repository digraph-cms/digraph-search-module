<?php
$search = $cms->helper('search');
$t = $cms->helper('templates');

$form = $search->form();
echo $form;

if ($package['url.args.search_q']) {
    if ($results = $search->search($package['url.args.search_q'])) {
        echo $cms->helper('paginator')->paginate(
            $results,//things to paginate
            $package,//package (to get url/arguments from)
            'page',//argument to use for page
            $cms->config['search.perpage'],//items per page
            function ($e) use ($t) {//callback given elements
                    return $t->render('digraph/search-result.twig', ['result'=>$e]);
            }
        );
    } else {
        echo "<div class='notification notification-notice'>No results</div>";
    }
}
