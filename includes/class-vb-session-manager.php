<?php
/**
 * Chat session and message management.
 *
 * Handles CRUD operations for chat sessions, messages, and theme version
 * snapshots stored in the WPVibe custom database tables.
 *
 * @package WPVibe
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class VB_Session_Manager {

	/**
	 * Get the sessions table name.
	 *
	 * @return string
	 */
	private function sessions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpvibe_sessions';
	}

	/**
	 * Get the messages table name.
	 *
	 * @return string
	 */
	private function messages_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpvibe_messages';
	}

	/**
	 * Get the theme versions table name.
	 *
	 * @return string
	 */
	private function versions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpvibe_theme_versions';
	}

	// -------------------------------------------------------------------------
	// Sessions
	// -------------------------------------------------------------------------

	/**
	 * Create a new chat session.
	 *
	 * @param int    $user_id WordPress user ID that owns the session.
	 * @param string $name    Human-readable session name.
	 * @param string $model   AI model identifier used for this session.
	 *
	 * @return int Newly created session ID.
	 *
	 * @throws RuntimeException If the database insert fails.
	 */
	public function create_session( int $user_id, string $name = 'Untitled Theme', string $model = '' ): int {
		global $wpdb;

		$result = $wpdb->insert(
			$this->sessions_table(),
			array(
				'user_id'      => $user_id,
				'session_name' => sanitize_text_field( $name ),
				'model_used'   => sanitize_text_field( $model ),
				'created_at'   => current_time( 'mysql', true ),
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			throw new RuntimeException(
				__( 'Failed to create chat session.', 'wpvibe' )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Retrieve a single session by ID.
	 *
	 * Performs an ownership check against the current WordPress user so that
	 * one user cannot read another user's sessions.
	 *
	 * @param int $session_id Session ID to fetch.
	 *
	 * @return array|null Session row as associative array, or null when not
	 *                    found / not owned by the current user.
	 */
	public function get_session( int $session_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->sessions_table()} WHERE id = %d",
				$session_id
			),
			ARRAY_A
		);

		if ( null === $row ) {
			return null;
		}

		// Ownership check: the session must belong to the current user.
		if ( (int) $row['user_id'] !== get_current_user_id() ) {
			return null;
		}

		return $row;
	}

	/**
	 * List sessions belonging to a user, newest first.
	 *
	 * @param int $user_id WordPress user ID.
	 * @param int $limit   Maximum number of sessions to return.
	 * @param int $offset  Number of rows to skip (for pagination).
	 *
	 * @return array List of session rows (associative arrays).
	 */
	public function list_sessions( int $user_id, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;

		$limit  = max( 1, min( $limit, 100 ) );  // Clamp to 1-100.
		$offset = max( 0, $offset );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->sessions_table()}
				 WHERE user_id = %d
				 ORDER BY updated_at DESC
				 LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Update mutable fields on a session.
	 *
	 * Accepted keys in $data: session_name, theme_slug, model_used.
	 * Unrecognised keys are silently ignored.
	 *
	 * @param int   $session_id Session ID to update.
	 * @param array $data       Key/value pairs of fields to change.
	 *
	 * @return bool True on success, false on failure or if the session does
	 *              not belong to the current user.
	 */
	public function update_session( int $session_id, array $data ): bool {
		global $wpdb;

		// Verify ownership first.
		if ( null === $this->get_session( $session_id ) ) {
			return false;
		}

		$allowed = array(
			'session_name' => '%s',
			'theme_slug'   => '%s',
			'model_used'   => '%s',
		);

		$update_fields  = array();
		$update_formats = array();

		foreach ( $allowed as $column => $format ) {
			if ( array_key_exists( $column, $data ) ) {
				$update_fields[ $column ] = sanitize_text_field( $data[ $column ] );
				$update_formats[]         = $format;
			}
		}

		if ( empty( $update_fields ) ) {
			return false;
		}

		// Touch updated_at timestamp.
		$update_fields['updated_at'] = current_time( 'mysql', true );
		$update_formats[]            = '%s';

		$result = $wpdb->update(
			$this->sessions_table(),
			$update_fields,
			array( 'id' => $session_id ),
			$update_formats,
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete a session and all related messages and theme versions.
	 *
	 * Because dbDelta does not create real FOREIGN KEY constraints, cascading
	 * deletes are handled manually in the correct order.
	 *
	 * @param int $session_id Session ID to delete.
	 *
	 * @return bool True on success, false on failure or ownership mismatch.
	 */
	public function delete_session( int $session_id ): bool {
		global $wpdb;

		// Verify ownership.
		if ( null === $this->get_session( $session_id ) ) {
			return false;
		}

		// 1. Delete theme versions for this session.
		$wpdb->delete(
			$this->versions_table(),
			array( 'session_id' => $session_id ),
			array( '%d' )
		);

		// 2. Delete messages for this session.
		$wpdb->delete(
			$this->messages_table(),
			array( 'session_id' => $session_id ),
			array( '%d' )
		);

		// 3. Delete the session itself.
		$result = $wpdb->delete(
			$this->sessions_table(),
			array( 'id' => $session_id ),
			array( '%d' )
		);

		return false !== $result;
	}

	// -------------------------------------------------------------------------
	// Messages
	// -------------------------------------------------------------------------

	/**
	 * Add a message to a session.
	 *
	 * @param int        $session_id  Session the message belongs to.
	 * @param string     $role        One of 'user', 'assistant', or 'system'.
	 * @param string     $content     Message text / AI response body.
	 * @param array|null $attachments Optional array of attachment metadata
	 *                                (images, Figma context, etc.).
	 * @param int        $token_count Number of tokens consumed by this message.
	 *
	 * @return int Newly created message ID.
	 *
	 * @throws RuntimeException  If the insert fails.
	 * @throws InvalidArgumentException If the role is not allowed.
	 */
	public function add_message( int $session_id, string $role, string $content, ?array $attachments = null, int $token_count = 0 ): int {
		global $wpdb;

		// Validate role.
		$allowed_roles = array( 'user', 'assistant', 'system' );
		if ( ! in_array( $role, $allowed_roles, true ) ) {
			throw new InvalidArgumentException(
				sprintf(
					/* translators: %s: The invalid role string. */
					__( 'Invalid message role: %s', 'wpvibe' ),
					$role
				)
			);
		}

		// Verify session ownership.
		if ( null === $this->get_session( $session_id ) ) {
			throw new RuntimeException(
				__( 'Session not found or access denied.', 'wpvibe' )
			);
		}

		// User messages are sanitized; assistant/system messages are stored raw
		// because they contain structured JSON that wp_kses_post corrupts.
		$safe_content = ( 'user' === $role ) ? wp_kses_post( $content ) : $content;

		$insert_data = array(
			'session_id'  => $session_id,
			'role'        => $role,
			'content'     => $safe_content,
			'attachments' => null !== $attachments ? wp_json_encode( $attachments ) : null,
			'token_count' => absint( $token_count ),
			'created_at'  => current_time( 'mysql', true ),
		);

		$formats = array( '%d', '%s', '%s', '%s', '%d', '%s' );

		$result = $wpdb->insert(
			$this->messages_table(),
			$insert_data,
			$formats
		);

		if ( false === $result ) {
			throw new RuntimeException(
				__( 'Failed to save chat message.', 'wpvibe' )
			);
		}

		// Touch the parent session's updated_at so it sorts to the top.
		$wpdb->update(
			$this->sessions_table(),
			array( 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $session_id ),
			array( '%s' ),
			array( '%d' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Retrieve messages for a session using cursor-based pagination.
	 *
	 * Messages are returned in ascending ID order (oldest first) so the chat
	 * UI can render them top-to-bottom.  The optional $before_id parameter
	 * enables "load older" pagination: pass the smallest message ID currently
	 * displayed to fetch the preceding batch.
	 *
	 * @param int      $session_id Session to fetch messages for.
	 * @param int|null $before_id  Only return messages with id < this value.
	 * @param int      $limit      Maximum number of messages to return.
	 *
	 * @return array List of message rows (associative arrays), oldest first.
	 */
	public function get_messages( int $session_id, ?int $before_id = null, int $limit = 50 ): array {
		global $wpdb;

		// Verify session ownership.
		if ( null === $this->get_session( $session_id ) ) {
			return array();
		}

		$limit = max( 1, min( $limit, 200 ) ); // Clamp to 1-200.

		if ( null !== $before_id ) {
			// Cursor pagination: get the $limit most recent messages older
			// than $before_id, then re-sort ASC for display order.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->messages_table()}
					 WHERE session_id = %d AND id < %d
					 ORDER BY id DESC
					 LIMIT %d",
					$session_id,
					$before_id,
					$limit
				),
				ARRAY_A
			);

			// Reverse so the result is in ascending (oldest-first) order.
			$rows = is_array( $rows ) ? array_reverse( $rows ) : array();
		} else {
			// No cursor: fetch the most recent $limit messages, then flip.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->messages_table()}
					 WHERE session_id = %d
					 ORDER BY id DESC
					 LIMIT %d",
					$session_id,
					$limit
				),
				ARRAY_A
			);

			$rows = is_array( $rows ) ? array_reverse( $rows ) : array();
		}

		// Decode the JSON attachments column for convenience.
		foreach ( $rows as &$row ) {
			if ( ! empty( $row['attachments'] ) ) {
				$decoded = json_decode( $row['attachments'], true );
				$row['attachments'] = is_array( $decoded ) ? $decoded : null;
			} else {
				$row['attachments'] = null;
			}
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Delete all messages for a session (clear chat history).
	 *
	 * @param int $session_id Session whose messages should be removed.
	 *
	 * @return bool True on success, false on failure or ownership mismatch.
	 */
	public function delete_messages( int $session_id ): bool {
		global $wpdb;

		// Verify session ownership.
		if ( null === $this->get_session( $session_id ) ) {
			return false;
		}

		$result = $wpdb->delete(
			$this->messages_table(),
			array( 'session_id' => $session_id ),
			array( '%d' )
		);

		return false !== $result;
	}

	// -------------------------------------------------------------------------
	// Theme Versions
	// -------------------------------------------------------------------------

	/**
	 * Save a theme version snapshot.
	 *
	 * When $version_number is provided it is used directly; otherwise the
	 * version number is automatically incremented based on the highest
	 * existing version for the session.
	 *
	 * @param int      $session_id     Session the version belongs to.
	 * @param int|null $version_number Explicit version number, or null to
	 *                                 auto-increment.
	 * @param string   $theme_slug     WordPress theme directory slug.
	 * @param array    $files          Array of theme file arrays
	 *                                 (each with 'path' and 'content' keys).
	 * @param int|null $message_id     ID of the AI message that produced this
	 *                                 version, or null if not yet available.
	 *
	 * @return int Newly created theme version ID.
	 *
	 * @throws RuntimeException If the insert fails or access is denied.
	 */
	public function save_theme_version( int $session_id, ?int $version_number, string $theme_slug, array $files, ?int $message_id = null ): int {
		global $wpdb;

		// Verify session ownership.
		if ( null === $this->get_session( $session_id ) ) {
			throw new RuntimeException(
				__( 'Session not found or access denied.', 'wpvibe' )
			);
		}

		// Determine the version number: use the explicit value or auto-increment.
		if ( null === $version_number ) {
			$max_version    = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(version_number) FROM {$this->versions_table()} WHERE session_id = %d",
					$session_id
				)
			);
			$version_number = $max_version + 1;
		}

		$insert_data = array(
			'session_id'     => $session_id,
			'version_number' => $version_number,
			'theme_slug'     => sanitize_text_field( $theme_slug ),
			'files_snapshot' => wp_json_encode( $files ),
			'created_at'     => current_time( 'mysql', true ),
		);
		$formats = array( '%d', '%d', '%s', '%s', '%s' );

		if ( null !== $message_id ) {
			$insert_data['message_id'] = $message_id;
			$formats[]                 = '%d';
		}

		$result = $wpdb->insert(
			$this->versions_table(),
			$insert_data,
			$formats
		);

		if ( false === $result ) {
			throw new RuntimeException(
				__( 'Failed to save theme version.', 'wpvibe' )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get all theme versions for a session, ordered by version number.
	 *
	 * @param int $session_id Session to fetch versions for.
	 *
	 * @return array List of version rows (associative arrays), oldest first.
	 */
	public function get_theme_versions( int $session_id ): array {
		global $wpdb;

		// Verify session ownership.
		if ( null === $this->get_session( $session_id ) ) {
			return array();
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->versions_table()}
				 WHERE session_id = %d
				 ORDER BY version_number ASC",
				$session_id
			),
			ARRAY_A
		);

		if ( ! is_array( $results ) ) {
			return array();
		}

		// Decode files_snapshot JSON for convenience.
		foreach ( $results as &$row ) {
			if ( ! empty( $row['files_snapshot'] ) ) {
				$decoded = json_decode( $row['files_snapshot'], true );
				$row['files_snapshot'] = is_array( $decoded ) ? $decoded : array();
			} else {
				$row['files_snapshot'] = array();
			}
		}
		unset( $row );

		return $results;
	}

	/**
	 * Get a single theme version by ID with ownership verification.
	 *
	 * @param int $version_id The version ID.
	 * @param int $user_id    The user requesting the version.
	 * @return array|null The version data or null if not found/not owned.
	 */
	public function get_theme_version( int $version_id, int $user_id ): ?array {
		global $wpdb;

		$version = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT v.* FROM {$this->versions_table()} v
				 INNER JOIN {$this->sessions_table()} s ON v.session_id = s.id
				 WHERE v.id = %d AND s.user_id = %d",
				$version_id,
				$user_id
			),
			ARRAY_A
		);

		if ( null === $version ) {
			return null;
		}

		// Decode the files snapshot from JSON.
		if ( isset( $version['files_snapshot'] ) && is_string( $version['files_snapshot'] ) ) {
			$version['files_snapshot'] = json_decode( $version['files_snapshot'], true ) ?? array();
		}

		return $version;
	}

	/**
	 * Restore a specific theme version (stub for Phase 3).
	 *
	 * In the full implementation this will read the files_snapshot from the
	 * requested version and write the files back to the theme directory via
	 * VB_Theme_Writer, then mark the version row with an applied_at timestamp.
	 *
	 * @param int $version_id Theme version row ID to restore.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function restore_theme_version( int $version_id ): bool {
		global $wpdb;

		// Fetch the version row.
		$version = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->versions_table()} WHERE id = %d",
				$version_id
			),
			ARRAY_A
		);

		if ( null === $version ) {
			return false;
		}

		// Verify session ownership through the parent session.
		if ( null === $this->get_session( (int) $version['session_id'] ) ) {
			return false;
		}

		// TODO: Phase 3 — Write files_snapshot back to theme directory via
		// VB_Theme_Writer and apply the theme.

		// Mark as applied.
		$result = $wpdb->update(
			$this->versions_table(),
			array( 'applied_at' => current_time( 'mysql', true ) ),
			array( 'id' => $version_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Count total sessions for a user.
	 *
	 * Useful for pagination controls in the UI.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return int Total number of sessions.
	 */
	public function count_sessions( int $user_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->sessions_table()} WHERE user_id = %d",
				$user_id
			)
		);
	}

	/**
	 * Count messages in a session.
	 *
	 * @param int $session_id Session ID.
	 *
	 * @return int Total number of messages, or 0 if access is denied.
	 */
	public function count_messages( int $session_id ): int {
		global $wpdb;

		// Verify session ownership.
		if ( null === $this->get_session( $session_id ) ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->messages_table()} WHERE session_id = %d",
				$session_id
			)
		);
	}
}
