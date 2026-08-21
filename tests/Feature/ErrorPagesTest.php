<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every status a visitor can actually land on gets the platform's own
 * branded page — before this, only 403 existed and everything else (a
 * mistyped URL, an expired Livewire session, a server error, scheduled
 * maintenance) fell through to Laravel's stock unbranded page in
 * production. See docs/audits/production.md §5.
 */
class ErrorPagesTest extends TestCase
{
    public function test_a_missing_route_shows_the_branded_404_page(): void
    {
        $this->get('/this-route-does-not-exist-anywhere')
            ->assertNotFound()
            ->assertSee("We couldn't find that page", false);
    }

    public function test_the_419_page_expired_view_renders(): void
    {
        $html = view('errors.419')->render();

        $this->assertStringContainsString('This page timed out', $html);
    }

    public function test_the_500_server_error_view_renders(): void
    {
        $html = view('errors.500')->render();

        $this->assertStringContainsString('Something went wrong', $html);
    }

    public function test_the_503_maintenance_view_renders(): void
    {
        $html = view('errors.503')->render();

        $this->assertStringContainsString('Back in a moment', $html);
    }

    public function test_every_error_page_uses_the_shared_public_layout(): void
    {
        foreach (['404', '419', '500', '503'] as $code) {
            $html = $code === '404'
                ? $this->get('/this-route-does-not-exist-anywhere')->getContent()
                : view("errors.{$code}")->render();

            $this->assertStringContainsString('<!DOCTYPE html>', $html, "errors.{$code} did not render the full page shell.");
        }
    }
}
