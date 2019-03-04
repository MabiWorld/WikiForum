<?php
/**
 * Helper class for WikiForum extension, for showing the overview, parsing special text, etc
 *
 * @file
 * @ingroup Extensions
 */
class WikiForum {

	/**
	 * Show an error message for the given title, message, and optional icon
	 *
	 * @param string $errorTitleMsg message key
	 * @param string $errorMessageMsg message key
	 * @param string $errorIcon icon finename (optional)
	 */
	static function showErrorMessage( $errorTitleMsg, $errorMessageMsg, $errorIcon = 'exclamation.png' ) {
		global $wgExtensionAssetsPath;

		$errorTitle = wfMessage( $errorTitleMsg )->text();
		$errorMessage = wfMessage( $errorMessageMsg )->text();

		$icon = '<img src="' . $wgExtensionAssetsPath . '/WikiForum/resources/images/' . $errorIcon . '" /> ';

		$output	= '<br /><table class="mw-wikiforum-frame"><tr><td>' . $icon . $errorTitle . '<p class="mw-wikiforum-descr">' . $errorMessage . '</p></td></tr></table>';

		return $output;
	}

	/**
	 * Show an overview of all available categories and their forums.
	 * Used in the special page class.
	 *
	 * @return HTML
	 */
	static function showOverview() {
		global $wgUser, $wgExtensionAssetsPath;

		$output = '';

		$dbr = wfGetDB( DB_REPLICA );
		$sqlCategories = $dbr->select(
			'wikiforum_category',
			'*',
			[],
			__METHOD__,
			[ 'ORDER BY' => 'wfc_sortkey ASC, wfc_category ASC' ]
		);

		if ( $sqlCategories->numRows() ) {
			$output .= WikiForumGui::showSearchbox();
		} else {
			$output .= wfMessage( 'wikiforum-forum-is-empty' )->parse(); // brand new installation, nothing here yet
		}

		foreach ( $sqlCategories as $sql ) {
			$cat = WFCategory::newFromSQL( $sql );

			$output .= $cat->showMain();
		}

		// Forum admins are allowed to add new categories
		if ( $wgUser->isAllowed( 'wikiforum-admin' ) ) {
			$icon = '<img src="' . $wgExtensionAssetsPath . '/WikiForum/resources/images/database_add.png" title="' . wfMessage( 'wikiforum-add-category' )->text() . '" /> ';
			$menuLink = $icon . '<a href="' . htmlspecialchars( SpecialPage::getTitleFor( 'WikiForum' )->getFullURL( [ 'wfaction' => 'addcategory' ] ) ) . '">' .
				wfMessage( 'wikiforum-add-category' )->text() . '</a>';
			$output .= WikiForumGui::showHeaderRow( '', $menuLink );
		}

		return $output;
	}

	/**
	 * Show the search results page.
	 *
	 * @param $what String: the search query string.
	 * @return HTML output
	 */
	static function showSearchResults( $what ) {
		global $wgOut, $wgRequest, $wgUser, $wgLang;

		$output = WikiForumGui::showSearchbox();
		$output .= WikiForumGui::showHeaderRow( '', '' );

		if ( strlen( $what ) > 1 ) {
			$i = 0;

			$title = wfMessage( 'wikiforum-search-hits', $i )->parse();
			$output .= WikiForumGui::showSearchHeader( $title );

			$dbr = wfGetDB( DB_REPLICA );
			// buildLike() will escape the query properly, add the word LIKE and the "double quotes"
			$likeString = $dbr->buildLike( $dbr->anyString(), $what, $dbr->anyString() );

			$limit = intval( wfMessage( 'wikiforum-max-threads-per-page' )->inContentLanguage()->plain() );

			$threadData = $dbr->select(
				'wikiforum_threads',
				'*',
				"(wft_thread_name $likeString OR wft_text $likeString)",
				__METHOD__,
				[ 'ORDER BY' => 'wft_posted_timestamp DESC', 'LIMIT' => $limit ]
			);

			foreach ( $threadData as $sql ) {
				$thread = WFThread::newFromSQL( $sql );
				$output .= $thread->showHeaderForSearch();

				$i++;
			}

			$replyData = $dbr->select(
				'wikiforum_replies',
				'*',
				"wfr_reply_text $likeString",
				__METHOD__,
				[ 'ORDER BY' => 'wfr_posted_timestamp DESC', 'LIMIT' => $limit ]
			);

			foreach ( $replyData as $sql ) {
				$reply = WFReply::newFromSQL( $sql );
				$output .= $reply->showForSearch();

				$i++;
			}

			$output .= '</table>' . WikiForumGui::showFrameFooter();
		} else {
			return self::showErrorMessage( 'wikiforum-error-search', 'wikiforum-error-search-missing-query' );
		}
		return $output;
	}

	/**
	 * Return an array of the most recent posts as indicated by the given filter.
	 *
	 * For each filter, a key of the same name with a ! in front being set to true
	 * indicates that that filter be inverted.
	 * Filters are:
	 *  'categories' => Only posts within the given categories.
	 *  'category_ids' => Same as above, but pass IDs instead of names.
	 *                    !categories inverts this.
	 *  'forums' => Only posts within the given forums.
	 *  'forum_ids' => Same as above, but pass IDs instead of names.
	 *                 !forums inverts this.
	 *  'users' => Only posts by the given users.
	 *  'limit' => How many posts that fit these filters to return.
	 *
	 * @param array $filters
	 * @return array
	 */
	static function getRecentPosts( $filters ) {
		$limit = 10;
		if ( isset( $filters[ 'limit' ] ) ) $limit = $filters[ 'limit' ];

		if ( isset( $filters[ 'category_ids' ] ) ) {
			$category_ids = $filters[ 'category_ids' ];
		} else {
			$category_ids = [];
		}

		$dbr = wfGetDB( DB_SLAVE );
		if ( isset( $filters[ 'categories' ] ) ) {
			// Convert categories to category_ids.
			$sqlCategories = $dbr->select(
				'wikiforum_category',
				'*',
				[ 'wfc_category_name' => $filters[ 'categories' ] ],
				__METHOD__,
				[]
			);

			foreach ( $sqlCategories as $sql ) {
				$cat = WFCategory::newFromSQL( $sql );

				array_push($category_ids, $cat->getId());
			}
		}

		// TODO: Forums, Users

		$replies = $dbr->select(
			[ 'wikiforum_replies', 'wikiforum_threads', 'wikiforum_forums' ],
			'*',
			[ 'wff_category' => $category_ids ],
			__METHOD__,
			[ 'ORDER BY' => 'wfr_posted_timestamp DESC', 'LIMIT' => $limit ],
			[
				'wikiforum_threads' => array( 'INNER JOIN', array( 'wfr_thread=wft_thread' ) ),
				'wikiforum_forums'  => array( 'INNER JOIN', array( 'wft_forum=wff_forum' ) ),
			]
		);

		$threads = $dbr->select(
			[ 'wikiforum_threads', 'wikiforum_forums' ],
			'*',
			[ 'wff_category' => $category_ids ],
			__METHOD__,
			[ 'ORDER BY' => 'wft_posted_timestamp DESC', 'LIMIT' => $limit ],
			[
				'wikiforum_forums'  => [ 'INNER JOIN', [ 'wft_forum=wff_forum' ] ],
			]
		);

		$posts = [];
		foreach ( $replies as $sql ) {
			array_push( $posts, WFReply::newFromSQL( $sql ) );
		}

		foreach ( $threads as $sql ) {
			array_push( $posts, WFThread::newFromSQL( $sql ) );
		}

		usort( $posts, function ( $a, $b ) {
        	return strcmp( $b->getPostedTimestamp(), $a->getPostedTimestamp() );
		} );

		return array_slice( $posts, 0, $limit );
	}

	/**
	 * Return a user object from fields from the DB
	 *
	 * @param int $userID
	 * @param string $userIP
	 * @return User|bool
	 */
	static function getUserFromDB( $userID, $userIP ) {
		if ( $userID ) {
			return User::newFromID( $userID );
		} else {
			return User::newFromName( $userIP, false );
		}
	}

	/**
	 * Get the link to the specified user's userpage (and group membership)
	 *
	 * @param User $user user object
	 * @return HTML
	 */
	public static function showUserLink( User $user, $showTitle = true ) {
		$username = $user->getName();

		if ( $user->isAnon() ) { // Do no further processing for anons, since anons cannot have groups.
			return Linker::link( Title::makeTitle( NS_USER_TALK, $username ), $username );
		}

		$retVal = Linker::link( Title::makeTitle( NS_USER, $username ), $username );

		$groups = $user->getEffectiveGroups();
		$groupText = '';

		if ( $showTitle ) {
			if ( in_array( 'sysop', $groups ) ) {
				$groupText .= wfMessage( 'word-separator' )->plain() .
					wfMessage(
						'parentheses',
						UserGroupMembership::getLink( 'sysop', RequestContext::getMain(), 'html', $username )
					)->text();

			} elseif ( in_array( 'forumadmin', $groups ) ) {
				$groupText .= wfMessage( 'word-separator' )->plain() .
					wfMessage(
						'parentheses',
						UserGroupMembership::getLink( 'forumadmin', RequestContext::getMain(), 'html', $username )
					)->text();
			}
		}

		Hooks::run( 'WikiForumSig', [ &$groupText, $user ] );

		$retVal .= $groupText;

		return $retVal;
	}

	/**
	 * Show the HTML for the avatar for the given user
	 *
	 * @param User $user
	 * @return string HTML, the avatar
	 */
	static function showAvatar( User $user ) {
		$avatar = '<div class="wikiforum-avatar-container">';
		if ( class_exists( 'wAvatar' ) ) {
			$avatarObj = new wAvatar( $user->getId(), 'l' );
			$avatar .= '<div class="wikiforum-avatar-image">';
			$avatar .= $avatarObj->getAvatarURL();
			$avatar .= '</div>';
		}

		$avatar .= '<div class="wikiforum-avatar-name">'
			. WikiForum::showUserLink( $user, false )
			. '</div>'
			. '</div>';

		return $avatar;
	}

	static function parseIt( $text ) {
		global $wgOut;

		$text = $wgOut->parse( $text );
		$text = self::parseLinks( $text );
		$text = self::parseQuotes( $text );

		return $text;
	}

	/**
	 * Replace links like '[thread#21]' with actual HTML links to the given thread
	 *
	 * @param string $text
	 * @return string
	 */
	static function parseLinks( $text ) {
		$text = preg_replace_callback(
			'/\[thread#(.*?)\]/i',
			'WikiForum::threadLinkFromID', // [ $this, 'getThreadTitle' ],
			$text
		);
		return $text;
	}

	/**
	 * Get a link to a thread from it's ID, for parseLinks() above
	 *
	 * @param int $id
	 * @return string|int
	 */
	static function threadLinkFromID( $id ) {
		$thread = WFThread::newFromID( $id );
		if ( $thread ) {
			return '<i>' . $thread->showLink() . '</i>';
		} else { // fallback, got to return something
			return $id;
		}
	}

	/**
	 * Parse quotes like '[quote=Author][/quote]' with actual HTML blockquotes
	 *
	 * @param string $text
	 * @return string
	 */
	static function parseQuotes( $text ) {
		$text = preg_replace(
			'/\[quote=(.*?)\]/',
			'<blockquote><p class="posted">\1</p><span>&raquo;</span>',
			$text
		);
		$text = str_replace(
			'[quote]',
			'<blockquote><span>&raquo;</span>',
			$text
		);
		$text = str_replace(
			'[/quote]',
			'<span>&laquo;</span></blockquote>',
			$text
		);
		return $text;
	}

	public static function deleteTags( $text ) {
		$text = preg_replace(
			'/\<WikiForumThread id=(.*?)\/\>/',
			'&lt;WikiForumThread id=\1/&gt;',
			$text
		);
		$text = preg_replace(
			'/\<WikiForumList(.*)\/>/',
			'&lt;WikiForumList \1/&gt;',
			$text
		);
		return $text;
	}

	/**
	 * Should we require the user to pass a captcha?
	 *
	 * @return bool
	 */
	public static function useCaptcha() {
		global $wgCaptchaClass, $wgCaptchaTriggers, $wgUser;
		return $wgCaptchaClass &&
			isset( $wgCaptchaTriggers['wikiforum'] ) &&
			$wgCaptchaTriggers['wikiforum'] &&
			!$wgUser->isAllowed( 'skipcaptcha' );
	}

	/**
	 * Return the HTML for the captcha
	 *
	 * @return string
	 */
	public static function getCaptcha( $out ) {
		wfSetupSession(); // NOTE: make sure we have a session. May be required for CAPTCHAs to work.
		$output = wfMessage( "captcha-sendemail" )->parseAsBlock();

		$captcha = ConfirmEditHooks::getInstance();
		$captcha->trigger = 'wikiforum';
		$captcha->action = 'post';
		$output .= $captcha->getForm( $out );

		return $output;
	}
}

