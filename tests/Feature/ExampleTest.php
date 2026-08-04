<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

// The homepage reads its content from the database now, so it needs a schema
// to render against.
uses(RefreshDatabase::class);

test('the application returns a successful response', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
