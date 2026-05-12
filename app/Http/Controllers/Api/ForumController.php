<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forum\StorePostRequest;
use App\Http\Requests\Forum\StoreReplyRequest;
use App\Http\Resources\ForumPostResource;
use App\Http\Resources\ForumReplyResource;
use App\Models\ForumPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ForumController extends Controller
{
    public function index(): JsonResponse
    {
        $posts = ForumPost::query()
            ->with([
                'user:id,name',
                'replies.user:id,name',
            ])
            ->latest()
            ->limit(20)
            ->get();

        return response()->json([
            'data' => ForumPostResource::collection($posts),
        ]);
    }

    public function store(StorePostRequest $request): JsonResponse
    {
        $post = $request->user()->forumPosts()->create($request->validated());
        $post->load('user:id,name');

        return response()->json([
            'message' => 'Postingan forum berhasil dibuat.',
            'data'    => new ForumPostResource($post),
        ], 201);
    }

    public function reply(StoreReplyRequest $request, ForumPost $post): JsonResponse
    {
        $reply = $post->replies()->create([
            'user_id' => $request->user()->id,
            'body'    => $request->validated('body'),
        ]);

        $reply->load('user:id,name');

        return response()->json([
            'message' => 'Balasan berhasil dikirim.',
            'data'    => new ForumReplyResource($reply),
        ], 201);
    }
}
