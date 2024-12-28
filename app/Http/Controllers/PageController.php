<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Book;
use App\Models\Event;
use App\Models\BlockPage;
use App\Models\News;
use App\Models\SidebarImage;
use App\Models\SliderImage;
use App\Models\MetaTag;
use Illuminate\Http\Request;

class PageController extends Controller
{
    /**
     * Show the application home page.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function home(Request $request)
    {
        $sliderImages = SliderImage::orderBy('display_order')->get();
        $news = News::orderBy('display_order')->get();
        $sidebarImages = SidebarImage::orderBy('display_order')->get();
        $blocks = BlockPage::where('page_id', 1)->where('status', 1)->orderBy('display_order')->get();

        $events = Event::where('type', bookAward())->whereDate('end_date', '<=', date("Y-m-d"))->orderBy('event_year', 'desc')->get();

        $metatags = MetaTag::where('page_id', 1)->first();

        return view('pages.home')->with([
            'sliderImages' => $sliderImages,
            'news' => $news,
            'events' => $events,
            'sidebarImages' => $sidebarImages,
            'metatags' => $metatags,
            'blocks' => $blocks
        ]);
    }

    /**
     * Switch the application language.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function switchLanguage(Request $request)
    {
        session()->put('lang', $request->lang);
        $cookie = cookie('lang', $request->lang, 50000);
        return response(['message' => 'Language Switched'])->cookie($cookie);
    }

    /**
     * Set cookie to not show subscription box again.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function viewedSubscriptionModal(Request $request)
    {
        session()->put('show_subscription_modal', false);
        $cookie = cookie('show_subscription_modal', false, 50000);
        return response(['message' => 'Cookie Updated'])->cookie($cookie);
    }

    /**
     * Display a listing of the resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $request_uri = ltrim($request->getRequestUri(), '/');
        $page = Page::where('slug', urldecode($request_uri));
        if(!optional(auth()->user())->isAdmin()) {
            $page->where('status', 1);
        }
        $page = $page->first();
        if(!$page){
            return abort(404);
        }
        $blocks = $page->blockPages;
        if($page->type === bookAwardPage()) {
            $open_event = Event::where('type', bookAward())->whereDate('start_date', '<=', date("Y-m-d"))
                                    ->whereDate('end_date', '>=', date("Y-m-d"))->first();

            $events = Event::where('type', bookAward())->whereDate('end_date', '<=', date("Y-m-d"))->orderBy('event_year', 'desc')->get();

            $metatags = $page->metaTag;

            return view('bookAwards.index')->with([
                'events' => $events,
                'blocks' => $blocks,
                'open_event' => $open_event,
                'metatags' => $metatags,
            ]);
        }
        if($page->type === theWAwardPage()) {
            $open_event = Event::where('type', theWAward())->whereDate('start_date', '<=', date("Y-m-d"))
                                        ->whereDate('end_date', '>=', date("Y-m-d"))->first();

            $events = Event::where('type', theWAward())->whereDate('end_date', '<=', date("Y-m-d"))->orderBy('event_year', 'desc')->get();

            $metatags = $page->metaTag;

            return view('theWAwards.index')->with([
                'events' => $events,
                'blocks' => $blocks,
                'open_event' => $open_event,
                'metatags' => $metatags,
            ]);
        }
        return abort(404);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Page  $page
     * @return \Illuminate\Http\Response
     */
    public function show(Page $page)
    {
        if($page->slug === 'home') {
            return redirect('/');
        }
        $blocks = $page->blockPages;
        $metatags = $page->metaTag;

        $books = Book::latest()->get();
        $hard_copy_books = $books->where('type', hardCopyBook());
        $ebooks = $books->where('type', eBook());

        return view('pages.index')->with([
            'blocks' => $blocks,
            'hard_copy_books' => $hard_copy_books,
            'ebooks' => $ebooks,
            'metatags' => $metatags,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Page  $page
     * @return \Illuminate\Http\Response
     */
    public function edit(Page $page)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Page  $page
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Page $page)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Page  $page
     * @return \Illuminate\Http\Response
     */
    public function destroy(Page $page)
    {
        //
    }
}
