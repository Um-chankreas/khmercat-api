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
    /**
     * List comments for a video. Guest-accessible.
     */
    public function index(VideoReview $video, Request $request)
    {
        $viewerId = $this->currentViewerId();

        $comments = $video->comments()
            ->with('user:id,name,username,profile_picture')
            ->withCount('likes')
            ->when($viewerId, fn ($q) => $q->withExists([
                'likes as is_liked' => fn ($q) => $q->where('user_id', $viewerId),
            ]))
            ->paginate($request->integer('per_page', 20));

        $responseData = [
            'contents' => $comments->items(),
            'meta' => [
                'current_page' => $comments->currentPage(),
                'last_page' => $comments->lastPage(),
                'per_page' => $comments->perPage(),
                'total' => $comments->total(),
                'has_more' => $comments->hasMorePages(),
            ],
        ];

        return ApiResponse::success($responseData, 'Comments retrieved successfully.');
    }

    /**
     * Post a comment on a video. Requires authentication. Broadcasts the new
     * comment to everyone currently viewing this video's comments.
     */
    public function store(VideoReview $video, Request $request)
    {
        $request->validate([
            'body' => 'required|string|max:1000',
            'parent_id' => 'nullable|integer|exists:video_comments,id',
        ]);

        $parent = null;
        if ($request->filled('parent_id')) {
            $parent = VideoComment::where('id', $request->integer('parent_id'))
                ->where('video_review_id', $video->id)
                ->first();

            if (! $parent) {
                return ApiResponse::error('The comment being replied to does not belong to this video.', 422);
            }
        }

        $comment = $video->comments()->create([
            'user_id' => auth()->id(),
            'parent_id' => $parent?->id,
            'body' => $request->body,
        ]);
        $comment->load('user:id,name,username,profile_picture');
        $comment->setAttribute('likes_count', 0);
        $comment->setAttribute('is_liked', false);

        broadcast(new CommentCreated($comment));

        if ($parent && $parent->user_id !== auth()->id()) {
            $parent->user->notify(new CommentReplied($comment, $parent));
        } elseif (! $parent && $video->user_id !== auth()->id()) {
            $video->user->notify(new VideoCommented($comment));
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

        $comment->delete();

        broadcast(new CommentDeleted($videoReviewId, $commentId));

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

        broadcast(new CommentLiked($comment->video_review_id, $comment->id, $likesCount));

        return ApiResponse::success([
            'likes_count' => $likesCount,
            'is_liked' => $isLiked,
        ], $isLiked ? 'Comment liked.' : 'Comment unliked.');
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
