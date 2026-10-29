<?php

namespace App\Launch;

/**
 * The launch plan a creator starts from.
 *
 * Every step is timed against launch day rather than a calendar date, so
 * the plan is useful before the date is fixed and follows it when it
 * moves. The order within each phase is the order to do things in.
 *
 * Steps that end in something to publish carry a content type, which is
 * what lets the plan offer to write it.
 */
final class Playbook
{
    public const PHASES = [
        'foundations' => 'Foundations',
        'audience' => 'Build the audience',
        'outreach' => 'Press and previews',
        'countdown' => 'Countdown',
        'launch' => 'Launch day',
        'campaign' => 'During the campaign',
    ];

    public const CATEGORIES = [
        'kickstarter' => 'Kickstarter',
        'bgg' => 'BoardGameGeek',
        'website' => 'Website',
        'email' => 'Email',
        'social' => 'Social',
        'outreach' => 'Outreach',
        'previews' => 'Previews',
        'ads' => 'Ads',
    ];

    /**
     * @return list<array{key: string, phase: string, category: string, title: string, details: string, offset_days: int, content_type: ?string}>
     */
    public static function steps(): array
    {
        return [
            // Foundations: everything else points at these, so they come first.
            self::step('ks_prelaunch_page', 'foundations', 'kickstarter', -90, 'Polish the Kickstarter pre-launch page',
                "The pre-launch page is where every follow happens, so it needs to sell the game on its own.\n\n- A strong cover image and a one-line pitch\n- The trailer, if it is ready\n- Player count, age and play time\n- A short reason to follow now: early-bird pricing or exclusive rewards"),
            self::step('follow_buttons', 'foundations', 'website', -88, 'Put "Follow on Kickstarter" everywhere',
                "Kickstarter followers are the number that matters most before launch: each one is notified the moment the campaign goes live.\n\n- The website header, hero and footer\n- Every social profile bio\n- Email signatures and the welcome email\n- Tag every link (for example ?ref=website_hero) so Kickstarter's referrer report shows what works"),
            self::step('bgg_listing', 'foundations', 'bgg', -87, 'Complete the BoardGameGeek listing',
                "Backers research games on BGG before pledging.\n\n- Correct player count, age and play time\n- Upload images: box, board, cards, a game in progress\n- Add links to the website, Kickstarter page and trailer\n- Upload the rules as a PDF"),
            self::step('bgg_badge', 'foundations', 'bgg', -86, 'Ask a BGG admin to link your account to the publisher entry',
                'Posts from an account with the publisher (or designer) badge read as official. Message an admin through GeekMail with links to the game, the publisher page and your website.'),
            self::step('welcome_email', 'foundations', 'email', -85, 'Write the welcome email for new subscribers',
                'The first email every new subscriber gets. Thank them, say what the game is in two sentences, and ask them to follow the Kickstarter page so they are notified at launch.', 'email'),
            self::step('search_console', 'foundations', 'website', -84, 'Request indexing for the key website pages',
                'In Google Search Console, use URL Inspection → Request indexing for the homepage, how to play, and any new blog posts, so search results pick up recent changes quickly.'),

            // Audience: steady weekly content beats a burst.
            self::step('bgg_diary_1', 'audience', 'bgg', -84, 'BGG designer diary #1: the core mechanic',
                'Post on your BGG blog, linked to the game so it shows on its Blogs tab. Explain the problem the core mechanic solves and one thing you changed during playtesting. Rewrite rather than copy any website post — BGG ranks well enough to outrank your own page.', 'bgg_diary'),
            self::step('bgg_welcome_thread', 'audience', 'bgg', -83, 'Start a "designers here, ask us anything" thread on BGG',
                "Post in the game page's General forum: who you are, the game in a paragraph, where it is up to, and a link to try it. Answer every reply.", 'bgg_forum_post'),
            self::step('bgg_diary_2', 'audience', 'bgg', -77, 'BGG designer diary #2: designing the cards',
                'How the cards work together, how you balanced the numbers, and a card that changed the most from first draft to final.', 'bgg_diary'),
            self::step('social_weekly', 'audience', 'social', -76, 'Post a behind-the-scenes update on social',
                'One post a week is plenty. Prototypes, art in progress, a playtest moment. Always end with the Kickstarter follow link.', 'social_post'),
            self::step('bgg_diary_3', 'audience', 'bgg', -70, 'BGG designer diary #3: creating the artwork',
                'Show early sketches next to the finished art. Images do the work here; keep the words short.', 'bgg_diary'),
            self::step('monthly_email', 'audience', 'email', -65, 'Send the monthly update to the mailing list',
                'What has happened since the last email, one piece of news or art, and the ask: follow the Kickstarter page.', 'email'),
            self::step('bgg_diary_4', 'audience', 'bgg', -63, 'BGG designer diary #4: behind the scenes of the trailer',
                'How the trailer was planned and filmed, with one or two photos from the shoot.', 'bgg_diary'),
            self::step('blog_post', 'audience', 'website', -60, 'Publish a search-friendly blog post',
                'Answer a question people search for (for example "best 2-player games like yours") and link to the game and the Kickstarter page. One good post a month builds traffic that keeps arriving.', 'blog_post'),
            self::step('bgg_diary_5', 'audience', 'bgg', -56, 'BGG designer diary #5: getting ready for print',
                'Proofs, the box, the board, what surprised you. End by saying the launch is coming soon.', 'bgg_diary'),

            // Outreach: reviewers need weeks to play and write.
            self::step('list_sites', 'outreach', 'outreach', -88, 'Ask "best of" list sites to include the game',
                'Find articles ranking for searches like "best [genre] board games" and email each author asking them to consider adding the game. Keep it to a few lines and a link to try it.', 'outreach_email'),
            self::step('pitch_reviewers', 'outreach', 'outreach', -84, 'Pitch reviewers for preview copies',
                'Start with reviewers who covered similar games — mention their review. Offer a preview copy with no strings attached, and say roughly when you launch.', 'outreach_email'),
            self::step('pitch_followup', 'outreach', 'outreach', -77, 'Follow up reviewers who have not replied',
                'One polite follow-up, a week after the first email. If there is still no reply, move on.', 'outreach_email'),
            self::step('ship_previews', 'outreach', 'previews', -63, 'Ship preview copies',
                'Reviewers need two to four weeks to play and write. Include a printed rules summary and a note with your launch window and press contact.'),
            self::step('bgg_news_pitch', 'outreach', 'bgg', -56, 'Pitch an origin-story designer diary to BGG News',
                'BGG News publishes designer diaries site-wide and schedules ahead. Pitch an original piece — how the game started — aiming for publication two to three weeks before launch.', 'bgg_diary'),
            self::step('confirm_previews', 'outreach', 'previews', -42, 'Check reviewers have the game and know the launch date',
                'A short check-in: did it arrive, any questions, and the date coverage would be most useful.', 'outreach_email'),
            self::step('press_kit', 'outreach', 'website', -35, 'Put together a press kit',
                'A page or folder with the pitch, the facts, high-resolution images, the trailer, founder photos and contact details. Link it from every pitch.'),

            // Countdown: momentum into launch day.
            self::step('announce_date', 'countdown', 'kickstarter', -21, 'Announce the launch date',
                'Everywhere at once: email, BGG news forum, social, the website FAQ. Only once the date is confirmed.', 'email'),
            self::step('bgg_news_forum', 'countdown', 'bgg', -20, 'Post the launch date in the BGG game news forum',
                "Short and factual: the date, what the game is, the Kickstarter follow link, and a thank-you to the people who've followed along.", 'bgg_forum_post'),
            self::step('campaign_rewards_post', 'countdown', 'email', -14, 'Tell the list what is in the campaign',
                'Pledge levels, early-bird rewards and what they get by backing on day one.', 'email'),
            self::step('share_previews', 'countdown', 'social', -12, 'Share preview coverage as it goes live',
                'Quote the best line, credit the reviewer, link to the review and the Kickstarter page.', 'social_post'),
            self::step('ks_page_final', 'countdown', 'kickstarter', -10, 'Finish and proof the campaign page',
                'Rewards, stretch goals, shipping, risks and challenges, FAQ. Ask two people who have never seen the game to read it.'),
            self::step('one_week_email', 'countdown', 'email', -7, 'Send the "one week to go" email',
                'The date, the early-bird reward, and one last ask to follow the Kickstarter page so they are notified.', 'email'),
            self::step('launch_assets', 'countdown', 'social', -3, 'Prepare launch-day posts and graphics',
                'Write every launch-day post in advance so the day is spent replying, not writing.', 'social_post'),
            self::step('launch_website', 'countdown', 'website', -2, 'Get the website ready to switch to "Back us now"',
                'Buttons, banners and the FAQ change from "follow" to "back us" — ready to go live the moment the campaign does.'),
            self::step('remind_reviewers', 'countdown', 'outreach', -1, 'Remind reviewers launch is tomorrow',
                'A one-line note with the live link they can use.', 'outreach_email'),

            // Launch day.
            self::step('go_live', 'launch', 'kickstarter', 0, 'Launch the campaign',
                'Go live early in the day for your main audience, so the first-day push gets a full day.'),
            self::step('launch_email', 'launch', 'email', 0, 'Send the launch email',
                'Within minutes of going live. Lead with the early-bird reward and how long it lasts.', 'email'),
            self::step('launch_social', 'launch', 'social', 0, 'Post the launch everywhere',
                'Every channel, plus a personal post from each of you.', 'social_post'),
            self::step('launch_bgg', 'launch', 'bgg', 0, 'Post the launch on BGG',
                "In the game's news forum and on your BGG blog.", 'bgg_forum_post'),
            self::step('launch_website_live', 'launch', 'website', 0, 'Switch the website to "Back us now"', 'Merge the changes prepared before launch.'),

            // During the campaign.
            self::step('first_update', 'campaign', 'kickstarter', 2, 'Post the first Kickstarter update',
                'Thank the first backers, share progress so far, and say what is coming next.', 'kickstarter_update'),
            self::step('weekly_update', 'campaign', 'kickstarter', 7, 'Post a weekly Kickstarter update',
                'Keep backers involved: a new reveal, a stretch goal, a playtest story.', 'kickstarter_update'),
            self::step('midpoint_push', 'campaign', 'email', 14, 'Mid-campaign push to non-backers',
                'Email subscribers who have not backed yet. New reason to back: a stretch goal, an add-on, a review.', 'email'),
            self::step('final_48h', 'campaign', 'email', 28, 'Final 48 hours reminder',
                'Kickstarter sends followers a 48-hour reminder; send your own to the mailing list too.', 'email'),
        ];
    }

    /** @return array{key: string, phase: string, category: string, title: string, details: string, offset_days: int, content_type: ?string} */
    private static function step(string $key, string $phase, string $category, int $offset, string $title, string $details, ?string $contentType = null): array
    {
        return [
            'key' => $key,
            'phase' => $phase,
            'category' => $category,
            'title' => $title,
            'details' => $details,
            'offset_days' => $offset,
            'content_type' => $contentType,
        ];
    }
}
