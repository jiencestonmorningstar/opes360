<?php

namespace App\Http\Controllers;

use App\Notifications\ContactMessageNotification;
use App\Support\BlogPosts;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Notification;

/**
 * The public marketing site: home, about, features, pricing and the contact
 * form. Plain Blade, not Livewire — these pages carry no state that needs a
 * round trip, so a static render is simplest and fastest for a first paint.
 */
class MarketingController extends Controller
{
    public function home()
    {
        return view('marketing.home');
    }

    public function about()
    {
        return view('marketing.about');
    }

    public function features()
    {
        return view('marketing.features');
    }

    public function pricing()
    {
        return view('marketing.pricing');
    }

    public function contact()
    {
        return view('marketing.contact');
    }

    public function blog()
    {
        return view('marketing.blog.index', [
            'posts' => BlogPosts::all(),
        ]);
    }

    public function blogShow(string $slug)
    {
        $post = BlogPosts::find($slug);

        abort_if($post === null, 404);

        return view('marketing.blog.show', [
            'slug' => $slug,
            'post' => $post,
            'bodyHtml' => BlogPosts::toHtml($post['body']),
        ]);
    }

    public function privacy()
    {
        return view('marketing.privacy');
    }

    public function terms()
    {
        return view('marketing.terms');
    }

    public function storeContact(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160'],
            'message' => ['required', 'string', 'max:4000'],
            // Honeypot: a real visitor never fills this in.
            'website_url' => ['prohibited'],
        ]);

        // Routed to a fixed admin mailbox rather than a User: nobody signed
        // in owns this message, so there is no notifiable account to attach
        // it to.
        Notification::route('mail', config('opes.contact.recipient'))
            ->notify(new ContactMessageNotification($data['name'], $data['email'], $data['message']));

        return redirect()->route('marketing.contact')
            ->with('status', "Thanks — we've got your message and will reply to {$data['email']} soon.");
    }
}
