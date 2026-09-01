<?php

use App\Modules\Social\Http\Controllers\SocialAccountController;
use App\Modules\Social\Http\Controllers\SocialPostController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'client-app'])->prefix('app/social')->name('client.social.')->group(function () {
    Route::get('/accounts', [SocialAccountController::class, 'index'])->name('accounts.index');
    Route::get('/accounts/connect/{network}', [SocialAccountController::class, 'connect'])->name('accounts.connect');
    Route::get('/accounts/callback/{network}', [SocialAccountController::class, 'callback'])->name('oauth.callback');
    Route::delete('/accounts/{account}', [SocialAccountController::class, 'disconnect'])->name('accounts.disconnect');
    Route::get('/accounts/{account}/creator-options', [SocialAccountController::class, 'creatorOptions'])->name('accounts.creator-options');

    Route::get('/posts', [SocialPostController::class, 'index'])->name('posts.index');
    Route::get('/composer', [SocialPostController::class, 'composer'])->name('composer');
    Route::post('/posts', [SocialPostController::class, 'store'])->name('posts.store')->middleware('limit:social_posts_per_month,social_posts');
    Route::get('/posts/{post}/edit', [SocialPostController::class, 'edit'])->name('posts.edit');
    Route::put('/posts/{post}', [SocialPostController::class, 'update'])->name('posts.update');
    Route::delete('/posts/{post}', [SocialPostController::class, 'destroy'])->name('posts.destroy');
    Route::delete('/posts/{post}/local-record', [SocialPostController::class, 'removeLocalRecord'])->name('posts.remove-local');
    Route::put('/posts/{post}/facebook/{account}', [SocialPostController::class, 'updatePublishedFacebook'])->name('posts.facebook.update');
    Route::delete('/posts/{post}/facebook/{account}', [SocialPostController::class, 'deletePublishedFacebook'])->name('posts.facebook.destroy');
    Route::delete('/posts/{post}/instagram/{account}', [SocialPostController::class, 'deletePublishedInstagram'])->name('posts.instagram.destroy');
    Route::put('/posts/{post}/youtube/{account}', [SocialPostController::class, 'updatePublishedYoutube'])->name('posts.youtube.update');
    Route::delete('/posts/{post}/youtube/{account}', [SocialPostController::class, 'deletePublishedYoutube'])->name('posts.youtube.destroy');
    Route::post('/posts/{post}/publish-now', [SocialPostController::class, 'publishNow'])->name('posts.publish-now');
    Route::post('/posts/{post}/cancel', [SocialPostController::class, 'cancel'])->name('posts.cancel');
    Route::post('/ai-generate', [SocialPostController::class, 'aiGenerate'])->name('ai-generate');
    Route::get('/calendar', [SocialPostController::class, 'calendar'])->name('calendar');
});
