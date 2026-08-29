<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * CIMS tidak punya landing page publik: akar aplikasi selalu melempar ke
     * halaman login (routes/web.php). Tes bawaan Laravel yang mengharapkan 200
     * di sini disesuaikan dengan perilaku sebenarnya, bukan sebaliknya.
     */
    public function test_the_root_url_sends_visitors_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
