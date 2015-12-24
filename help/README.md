# Buoy Help

This directory contains markdown files for the Better Angels' Buoy on-screen help tabs.

Each help file is contained within a directory matching the locale string of the WordPress installation. The file names reference the `$action` and `$id` members of the [`WP_Screen`](https://developer.wordpress.org/reference/classes/wp_screen/) class, followed by an optional numeric priority suffix. For instance, take the following directory structure:

    help/
    ├── README.md
    └── en_US
        ├── addbuoy_team-10.md
        ├── buoy_team_page_buoy_safety_info.md
        ├── buoy_team_page_buoy_team_membership-10.md
        ├── buoy_team_page_buoy_team_membership-20.md
        ├── edit-buoy_team-10.md
        ├── profile-20.md
        └── settings_page_buoy_settings.md

This provides seven different help tabs on six different admin pages when WordPress is configured to use the `en_US` locale (American English). The two tabs on the "team membership" page are numerically sorted by applying a suffix: `-10` comes before `-20`, and so on. If a priority suffix is not present, that tab uses the default value set by WordPress (`10`). The "profile" page specifies a priority of `20` so it appears after any help tabs added by WordPress itself already on that page.
