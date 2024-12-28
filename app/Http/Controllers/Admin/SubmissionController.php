<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Repositories\CommonRepository;
use App\Models\Submission;
use App\Models\Event;
use App\Models\Review;
use Illuminate\Http\Request;

class SubmissionController extends Controller
{
    protected $repo;

    public function __construct(Submission $model)
    {
        $this->repo = new CommonRepository($model);
    }

    public function getList(Request $request)
    {
        $orderableCols = ['created_at', 'name', 'payment_status', 'event.amount', 'is_submitted'];
        $searchableCols = ['name'];
        $whereChecks = ['event_id'];
        $whereOps = ['='];
        $whereVals = [$request->event_id];
        $with = ['event', 'reviews'];
        $withCount = [];

        if(auth()->user()->isJuryUser()) {
            $event = Event::find($request->event_id);

            if(!$event) {
                return [
                    'recordsFiltered' => 0,
                    'recordsTotal' => 0,
                    'data' => [],
                ];
            }

            $juryIsAllowed = optional(optional($event)->jury)->where('pivot.user_id', auth()->id())->isNotEmpty();

            if(!$juryIsAllowed) {
                return [
                    'recordsFiltered' => 0,
                    'recordsTotal' => 0,
                    'data' => [],
                ];
            }

            $orderableCols = ['created_at', 'name'];
            $with[] = 'review';

            $whereChecks[] = 'is_submitted';
            $whereOps[] = '=';
            $whereVals[] = 1;

            if($event->has_price){
                $whereChecks[] = 'payment_status';
                $whereOps[] = '=';
                $whereVals[] = 1;
            }
        }

        $data = $this->repo->getData($request, $with, $withCount, $whereChecks, $whereOps, $whereVals, $searchableCols, $orderableCols);

        $serial = ($request->start ?? 0) + 1;
        collect($data['data'])->map(function ($item) use (&$serial) {
            $item['serial'] = $serial++;
            $sum = $item->reviews->sum(function ($review) {
                return $review->rating;
            });
            $reviews_count = $item->reviews->count() ?? 0;
            if($reviews_count > 0) {
                $ratingAggregate = $sum > 0 ? $sum / $reviews_count : 0;
                $item['avg_rating'] = number_format((float)$ratingAggregate, 2, '.', ',');
            } else {
                $item['avg_rating'] = 'To be reviewed';
            }

            return $item;
        });

        return response($data);
    }

    public function selectList(Request $request)
    {
        $submissions = Submission::where('event_id', $request->event_id);
        if($request->search){
            $search = $request->search;
            $submissions->where(function($q) use($search) {
                $q->where('name', 'like', "%$search%");
                $q->orWhereHas('user', function($q) use($search) {
                    $q->where('first_name', 'like', "%$search%");
                    $q->orWhere('last_name', 'like', "%$search%");
                });
            });
        }
        $submissions = $submissions->orderBy('name', 'asc')->limit(15)->get();
        $data = [];
        foreach($submissions as $submission){
            $data[] = [
                'id' => $submission->id,
                'text' => $submission->desc_name
            ];
        }
        return response($data);
    }

    public function getReviews(Request $request)
    {
        $submission = Submission::findOrFail($request->submissions_id);

        return $submission->reviews;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if(auth()->user()->isAdmin()) {
            $events = Event::latest()->pluck('name', 'id');

            return view('admin.submissions.index')->with([
                'events' => $events
            ]);
        } else {
            $events = Event::whereHas('jury', function($q){
                $q->where('user_id', auth()->id());
            })->latest()->pluck('name', 'id');

            return view('admin.submissions.indexJury')->with([
                'events' => $events
            ]);
        }
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
     * @param  \App\Models\Submission  $submission
     * @return \Illuminate\Http\Response
     */
    public function show(Submission $submission)
    {
        $event = Event::find($submission->event_id);
        $submissions = $event->submissions->sortByDesc('id');

        if(auth()->user()->isJuryUser()) {
            $submissions = $submissions->filter(function ($value, $key) use ($event) {
                if(!$value->getAttributes()['is_submitted']) {
                    return false;
                }
                if($event->has_price){
                    if(!$value->getAttributes()['payment_status']){
                        return false;
                    }
                }
                return true;
            });

            $rating = Review::where([
                'user_id' => auth()->id(),
                'submission_id' => $submission->id,
            ])->first();
        }

        $next_submission = $submissions->where('id', '<', $submission->id)->max('id');
        $prev_submission = $submissions->where('id', '>', $submission->id)->min('id');

        return view('admin.submissions.show')->with([
            'submission' => $submission,
            'rating' => $rating ?? null,
            'prev_submission' => $prev_submission ?? null,
            'next_submission' => $next_submission ?? null
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Submission  $submission
     * @return \Illuminate\Http\Response
     */
    public function edit(Submission $submission)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Submission  $submission
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Submission $submission)
    {
        if($request->rating < 1) {
            return response([]);
        }

        $review = Review::where([
            'user_id' => auth()->id(),
            'submission_id' => $submission->id,
        ])->first();

        if(!$review){
            $review = new Review;
            $review->user_id = auth()->id();
            $review->submission_id = $submission->id;
        }

        $review->rating = $request->rating;
        $review->comments = $request->comments;
        $review->save();
        $wasChanged = $review->wasChanged();

        if($wasChanged) {
            return response([
                'message' => 'Review for submission has been saved.',
                'status' => 'success'
            ]);
        } else {
            return response([]);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Submission  $submission
     * @return \Illuminate\Http\Response
     */
    public function destroy(Submission $submission)
    {
        foreach ($submission->files as $key => $file) {
            deleteFileOnS3($file);
        }
        $submission->delete();
        return response(['message' => 'Submission has been deleted!']);
    }
}
