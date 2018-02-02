# Mabinogi World's version of WikiForum #
In order to provide a decent forum experience, we have had to upgrade the WikiForum extension to meet the minimum functionality requirements of a real forum. This also include some features specific to the MWW experience, so there are no plans to contribute much of it back to the official WikiForum branch (though they are welcome to take whatever).

## Changes made ##
A project page can be found [here](https://forums.mabi.world/thread/These_forums_are_beta_af).

* Various custom URL system support in order to mask the use of a special page.
  * This includes the addition of the following global variables:
  * $wgWikiForumOverviewPath
  * $wgWikiForumCategoryPath
  * $wgWikiForumForumPath
  * $wgWikiForumThreadByNamePath
  * $wgWikiForumThreadByNumberPath
* A better thread summary showing info about the latest post in the "Latest thread" column of forum summaries.
* Addition of a "new" thread icon, for recent threads.
* Move threads/replies functionality added.
  * Similarly, lots of fixes for delete function was needed.
* Allowed users to edit and delete their own posts.
* Conversion of post box to use WikiEditor.
* Various formatting adjustments.
* Fixes to page number display
* Jump to latest page
  * Form is: ThreadName/latest
