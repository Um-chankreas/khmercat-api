<?php

namespace App\Http\Controllers\Api;

use App\Events\CommentCreated;
use App\Events\CommentDeleted;
use App\Events\CommentLiked;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CommentLike;
use App\Models\VideoComment;
use App\Models\VideoReview;
use App\Notifications\CommentReplied;
use App\Notifications\VideoCommented;
use Illuminate\Http\Request;
use Throwable;

class CommentController extends Controller
{
    /** Replies embedded under each top-level comment in the list response. */
    private const REPLY_PREVIEW = 3;

    private const USER_FIELDS = 'user:id,name,username,profile_picture,is_verified';

    /**
     * Top-level comments for a video, newest first, each with its first few
     * replies (oldest first) and `replies_count`. Guest-accessible.
     *
     * Every comment and reply carries `is_creator` (written by the video's
     * uploader). `meta.total_all` counts replies too, for the header badge.
     */
    public function index(VideoReview $video, Request $request)
    {
        $viewerId = $this->currentViewerId();

        $comments = $video->comments()
            ->whereNull('parent_id')
            ->whereHas('user', fn ($u) => $u->active())
            ->with(self::USER_FIELDS)
            ->withCount(['likes', 'replies'])
            ->when($viewerId, fn ($q) => $this->withLikedByViewer($q, $viewerId))
            ->with(['replies' => fn ($q) => $this->replyQuery($q, $viewerId)->limit(self::REPLY_PREVIEW)])
            ->paginate($request->integer('per_page', 20));

        foreach ($comments->items() as $comment) {
            $this->decorate($comment, $video);
            $comment->replies->each(fn ($reply) => $this->decorate($reply, $video));
        }

        $responseData = [
            'contents' => $comments->items(),
            'meta' => [
                'current_page' => $comments->currentPage(),
                'last_page' => $comments->lastPage(),
                'per_page' => $comments->perPage(),
                'total' => $comments->total(),
                'total_all' => $video->comments()->count(),
                'has_more' => $comments->hasMorePages(),
            ],
        ];

        return ApiResponse::success($responseData, 'Comments retrieved successfully.');
    }

    /**
     * Replies to one top-level comment, oldest first. Guest-accessible. Used
     * by "View more replies" once the embedded preview runs out.
     */
    public function replies(VideoComment $comment, Request $request)
    {
        $viewerId = $this->currentViewerId();
        $video = $comment->videoReview()->first(['id', 'user_id']);

        $replies = $this->replyQuery($comment->replies(), $viewerId)
            ->paginate($request->integer('per_page', 10));

        foreach ($replies->items() as $reply) {
            $this->decorate($reply, $video);
        }

        return ApiResponse::success([
            'contents' => $replies->items(),
            'meta' => [
                'current_page' => $replies->currentPage(),
                'total' => $replies->total(),
                'has_more' => $replies->hasMorePages(),
            ],
        ], 'Replies retrieved successfully.');
    }

    /**
     * Post a comment on a video, or a reply when `parent_id` is given.
     * Replies stay one level deep: replying to a reply attaches to the same
     * top-level comment, while the notification still goes to the person
     * whose reply was answered. Broadcasts to everyone viewing the comments.
     */
    public function store(VideoReview $video, Request $request)
    {
        $request->validate([
            'body' => 'required|string|max:1000',
            'parent_id' => 'nullable|integer|exists:video_comments,id',
        ]);

        $repliedTo = null;
        if ($request->filled('parent_id')) {
            $repliedTo = VideoComment::where('id', $request->integer('parent_id'))
                ->where('video_review_id', $video->id)
                ->first();

            if (! $repliedTo) {
                return ApiResponse::error('The comment being replied to does not belong to this video.', 422);
            }
        }

        $comment = $video->comments()->create([
            'user_id' => auth()->id(),
            'parent_id' => $repliedTo ? ($repliedTo->parent_id ?? $repliedTo->id) : null,
            'body' => $request->body,
        ]);
        $comment->load(self::USER_FIELDS);
        $comment->setAttribute('likes_count', 0);
        $comment->setAttribute('is_liked', false);
        $comment->setAttribute('replies_count', 0);
        $comment->setRelation('replies', collect());
        $this->decorate($comment, $video);

        $this->sideEffect(fn () => broadcast(new CommentCreated($comment)));

        if ($repliedTo && $repliedTo->user_id !== auth()->id()) {
            $this->sideEffect(fn () => $repliedTo->user->notify(new CommentReplied($comment, $repliedTo)));
        } elseif (! $repliedTo && $video->user_id !== auth()->id()) {
            $this->sideEffect(fn () => $video->user->notify(new VideoCommented($comment)));
        }

        return ApiResponse::success($comment, 'Comment posted successfully.', 201);
    }

    /**
     * Delete a comment. Only the comment's author may delete it. Broadcasts
     * the deletion to everyone currently viewing this video's comments.
     */
    public function destroy(VideoComment $comment)
    {
        if ($comment->user_id !== auth()->id()) {
            return ApiResponse::error('You can only delete your own comments.', 403);
        }

        $videoReviewId = $comment->video_review_id;
        $commentId = $comment->id;
        $parentId = $comment->parent_id;
        // Replies are removed with it (cascade), so clients drop them too.
        $removed = 1 + $comment->replies()->count();

        $comment->delete();

        $this->sideEffect(fn () => broadcast(new CommentDeleted($videoReviewId, $commentId, $parentId, $removed)));

        return ApiResponse::success(null, 'Comment deleted successfully.');
    }

    /**
     * Toggle the signed-in user's like on a comment. Broadcasts the updated
     * count (not `is_liked` — see CommentLiked's doc comment) to everyone
     * currently viewing this video's comments.
     */
    public function toggleLike(VideoComment $comment)
    {
        $userId = auth()->id();

        $like = CommentLike::where('video_comment_id', $comment->id)->where('user_id', $userId)->first();

        if ($like) {
            $like->delete();
            $isLiked = false;
        } else {
            CommentLike::create(['video_comment_id' => $comment->id, 'user_id' => $userId]);
            $isLiked = true;
        }

        $likesCount = $comment->likes()->count();

        $this->sideEffect(fn () => broadcast(new CommentLiked($comment->video_review_id, $comment->id, $likesCount)));

        return ApiResponse::success([
            'likes_count' => $likesCount,
            'is_liked' => $isLiked,
        ], $isLiked ? 'Comment liked.' : 'Comment unliked.');
    }

    /**
     * Shared shape for replies: author, like count, viewer's like, oldest first.
     */
    private function replyQuery($query, ?int $viewerId)
    {
        return $query
            // Deactivated accounts' replies stay hidden until they're back.
            ->whereHas('user', fn ($u) => $u->active())
            ->with(self::USER_FIELDS)
            ->withCount('likes')
            ->when($viewerId, fn ($q) => $this->withLikedByViewer($q, $viewerId))
            ->reorder()
            ->oldest();
    }

    private function withLikedByViewer($query, int $viewerId)
    {
        return $query->withExists([
            'likes as is_liked' => fn ($q) => $q->where('user_id', $viewerId),
        ]);
    }

    /**
     * Marks comments written by the video's uploader ("Creator" badge).
     */
    private function decorate(VideoComment $comment, VideoReview $video): void
    {
        $comment->setAttribute('is_creator', $comment->user_id === $video->user_id);
    }

    /**
     * Resolve the signed-in user's ID on a public route without forcing
     * auth. Returns null for guests or invalid/missing tokens.
     */
    private function currentViewerId(): ?int
    {
        try {
            return auth('api')->id();
        } catch (Throwable $e) {
            return null;
        }
    }
}
