/**
 * WordPress REST API client for WPVibe.
 *
 * All requests are routed through the WP REST API using the nonce
 * and base URL provided by the server-rendered `window.wpvibeData`.
 */

import type {
  AIStreamChunk,
  Attachment,
  FigmaContext,
  FigmaFrame,
  Message,
  Model,
  Session,
  ThemeVersion,
} from '@/editor/types';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function getRestUrl(): string {
  return window.wpvibeData.restUrl;
}

function getNonce(): string {
  return window.wpvibeData.nonce;
}

/**
 * Build standard headers for WP REST requests.
 * WordPress expects `X-WP-Nonce` for cookie-based auth.
 */
function headers(json = true): HeadersInit {
  const h: HeadersInit = {
    'X-WP-Nonce': getNonce(),
  };
  if (json) {
    h['Content-Type'] = 'application/json';
  }
  return h;
}

/**
 * Thin wrapper around `fetch` that raises on non-2xx responses.
 */
async function apiFetch<T>(
  path: string,
  init: RequestInit = {},
): Promise<T> {
  // When WP uses plain permalinks the restUrl already contains "?rest_route=..."
  // so any query string in `path` must use "&" instead of "?".
  const base = getRestUrl();
  const url = base.includes('?')
    ? `${base}${path.replace('?', '&')}`
    : `${base}${path}`;
  const response = await fetch(url, {
    ...init,
    headers: {
      ...headers(init.body !== undefined && !(init.body instanceof FormData)),
      ...(init.headers ?? {}),
    },
  });

  if (!response.ok) {
    let detail = '';
    try {
      const body = await response.json();
      detail = body?.message ?? body?.error ?? '';
    } catch {
      // body was not JSON - ignore
    }
    throw new Error(
      detail || `Request failed: ${response.status} ${response.statusText}`,
    );
  }

  // 204 No Content - nothing to parse
  if (response.status === 204) {
    return undefined as unknown as T;
  }

  return response.json() as Promise<T>;
}

// ---------------------------------------------------------------------------
// Sessions
// ---------------------------------------------------------------------------

/**
 * Fetch all chat sessions for the current user.
 */
export async function getSessions(): Promise<Session[]> {
  const data = await apiFetch<{ sessions: Session[] }>('sessions');
  return data.sessions;
}

/**
 * Create a new chat session.
 * If themeSlug is provided, the existing theme's files are imported.
 */
export async function createSession(
  name?: string,
  themeSlug?: string,
): Promise<Session> {
  return apiFetch<Session>('sessions', {
    method: 'POST',
    body: JSON.stringify({
      session_name: name ?? 'Untitled Theme',
      ...(themeSlug ? { theme_slug: themeSlug } : {}),
    }),
  });
}

// ---------------------------------------------------------------------------
// Installed WordPress Themes
// ---------------------------------------------------------------------------

export interface WPTheme {
  slug: string;
  name: string;
  version: string;
  author: string;
  description: string;
  screenshot: string;
  isActive: boolean;
}

/**
 * Fetch all installed WordPress themes.
 */
export async function getInstalledThemes(): Promise<WPTheme[]> {
  const data = await apiFetch<{ themes: WPTheme[] }>('wp-themes');
  return data.themes;
}

// ---------------------------------------------------------------------------
// Chat History
// ---------------------------------------------------------------------------

interface HistoryResponse {
  messages: Message[];
  session: Session;
}

/**
 * Load conversation history for a session.
 *
 * @param sessionId - The session to load messages for.
 * @param beforeId  - Optional cursor for pagination (load messages older than this id).
 * @param limit     - Max number of messages to return (server default applies if omitted).
 */
export async function getHistory(
  sessionId: number,
  beforeId?: number,
  limit?: number,
): Promise<HistoryResponse> {
  const params = new URLSearchParams();
  params.set('session_id', String(sessionId));
  if (beforeId !== undefined) params.set('before_id', String(beforeId));
  if (limit !== undefined) params.set('limit', String(limit));

  return apiFetch<HistoryResponse>(`chat-history?${params.toString()}`);
}

/**
 * Delete all messages in a session.
 */
export async function clearHistory(sessionId: number): Promise<void> {
  const params = new URLSearchParams();
  params.set('session_id', String(sessionId));

  return apiFetch<void>(`chat-history?${params.toString()}`, {
    method: 'DELETE',
  });
}

// ---------------------------------------------------------------------------
// Models
// ---------------------------------------------------------------------------

interface ModelsResponse {
  models: Model[];
  currentModel: string;
}

/**
 * Retrieve models available for the current API key configuration.
 */
export async function getModels(): Promise<ModelsResponse> {
  return apiFetch<ModelsResponse>('models');
}

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

interface SettingsPayload {
  selected_model?: string;
}

/**
 * Persist editor settings (model selection, preferences, etc.).
 */
export async function saveSettings(settings: SettingsPayload): Promise<void> {
  return apiFetch<void>('save-settings', {
    method: 'POST',
    body: JSON.stringify(settings),
  });
}

// ---------------------------------------------------------------------------
// Image Upload
// ---------------------------------------------------------------------------

export interface UploadedImage {
  id: string;
  url: string;
  thumbnailUrl: string;
  mediaType: string;
}

/**
 * Upload a reference image via the WordPress media pipeline.
 * Returns metadata the caller can attach to a chat message.
 */
export async function uploadImage(file: File): Promise<UploadedImage> {
  const formData = new FormData();
  formData.append('file', file);

  // Build URL using the same logic as apiFetch to handle both
  // pretty permalinks and ?rest_route= formats.
  const base = getRestUrl();
  const path = 'upload-image';
  const url = base.includes('?')
    ? `${base}${path}`
    : `${base}${path}`;

  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'X-WP-Nonce': getNonce(),
      // Do NOT set Content-Type -- the browser sets it with the boundary
    },
    body: formData,
  });

  if (!response.ok) {
    let detail = '';
    try {
      const body = await response.json();
      detail = body?.message ?? body?.error ?? '';
    } catch {
      // ignore
    }
    throw new Error(detail || `Upload failed: ${response.status}`);
  }

  return response.json() as Promise<UploadedImage>;
}

// ---------------------------------------------------------------------------
// Streaming Chat
// ---------------------------------------------------------------------------

export interface StreamChatOptions {
  sessionId: number;
  message: string;
  attachments?: Attachment[];
  signal?: AbortSignal;
}

/**
 * Send a chat message and yield streamed SSE chunks.
 *
 * This is an async generator -- callers iterate with `for await...of`.
 *
 * Chunks follow the `AIStreamChunk` interface:
 *   - `text_delta`   : incremental assistant text
 *   - `theme_update` : a new theme version was generated
 *   - `error`        : server-side error
 *   - `done`         : stream finished, includes final messageId
 *
 * The request body is sent as JSON; the response is `text/event-stream`.
 */
export async function* streamChat(
  options: StreamChatOptions,
): AsyncGenerator<AIStreamChunk, void, undefined> {
  const { sessionId, message, attachments, signal } = options;

  const url = `${getRestUrl()}chat`;
  const response = await fetch(url, {
    method: 'POST',
    headers: headers(),
    body: JSON.stringify({
      session_id: sessionId,
      message,
      attachments: attachments ?? [],
    }),
    signal,
  });

  if (!response.ok) {
    let detail = '';
    try {
      const body = await response.json();
      detail = body?.message ?? body?.error ?? '';
    } catch {
      // ignore
    }
    yield {
      type: 'error',
      error: detail || `HTTP ${response.status}: ${response.statusText}`,
    };
    return;
  }

  if (!response.body) {
    yield { type: 'error', error: 'Response body is not readable.' };
    return;
  }

  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';

  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;

      buffer += decoder.decode(value, { stream: true });

      // SSE events are separated by double newlines.
      const parts = buffer.split('\n\n');
      // The last element is either an incomplete event or empty string.
      buffer = parts.pop() ?? '';

      for (const part of parts) {
        if (!part.trim()) continue;

        // Each SSE event can have multiple lines; we only care about `data:`.
        const dataLine = part
          .split('\n')
          .find((line) => line.startsWith('data: ') || line.startsWith('data:'));

        if (!dataLine) continue;

        const jsonStr = dataLine.startsWith('data: ')
          ? dataLine.slice(6)
          : dataLine.slice(5);

        // The SSE spec allows `data: [DONE]` as a terminal sentinel.
        if (jsonStr.trim() === '[DONE]') {
          return;
        }

        try {
          const chunk = JSON.parse(jsonStr) as AIStreamChunk;
          yield chunk;

          // If the server signals completion, stop reading.
          if (chunk.type === 'done' || chunk.type === 'error') {
            return;
          }
        } catch {
          // Malformed JSON -- skip this event silently.
        }
      }
    }

    // Flush any remaining buffer content after the stream closes.
    if (buffer.trim()) {
      const dataLine = buffer
        .split('\n')
        .find((line) => line.startsWith('data: ') || line.startsWith('data:'));
      if (dataLine) {
        const jsonStr = dataLine.startsWith('data: ')
          ? dataLine.slice(6)
          : dataLine.slice(5);
        try {
          const chunk = JSON.parse(jsonStr) as AIStreamChunk;
          yield chunk;
        } catch {
          // ignore
        }
      }
    }
  } finally {
    reader.releaseLock();
  }
}

// ---------------------------------------------------------------------------
// Theme Versions
// ---------------------------------------------------------------------------

/**
 * Fetch theme version history for a session.
 */
export async function getThemeVersions(sessionId: number): Promise<ThemeVersion[]> {
  const params = new URLSearchParams();
  params.set('session_id', String(sessionId));

  const data = await apiFetch<{ versions: ThemeVersion[] }>(
    `theme-versions?${params.toString()}`
  );
  return data.versions;
}

/**
 * Restore a specific theme version to disk and get a fresh preview URL.
 */
export async function restoreVersion(
  sessionId: number,
  versionIndex: number,
): Promise<{ success: boolean; previewUrl: string }> {
  return apiFetch<{ success: boolean; previewUrl: string }>('restore-version', {
    method: 'POST',
    body: JSON.stringify({
      session_id: sessionId,
      version_index: versionIndex,
    }),
  });
}

// ---------------------------------------------------------------------------
// Theme Actions
// ---------------------------------------------------------------------------

/**
 * Apply a theme version to the WordPress site.
 * If versionId is omitted, applies the latest version.
 */
export async function applyTheme(
  sessionId: number,
  versionId?: number,
): Promise<{ success: boolean; themeSlug: string }> {
  return apiFetch<{ success: boolean; themeSlug: string }>('apply-theme', {
    method: 'POST',
    body: JSON.stringify({
      session_id: sessionId,
      ...(versionId !== undefined ? { version_id: versionId } : {}),
    }),
  });
}

/**
 * Export a theme version as a downloadable ZIP file.
 * If versionId is omitted, exports the latest version.
 */
export async function exportTheme(
  sessionId: number,
  versionId?: number,
): Promise<{ success: boolean; url: string }> {
  return apiFetch<{ success: boolean; url: string }>('export', {
    method: 'POST',
    body: JSON.stringify({
      session_id: sessionId,
      ...(versionId !== undefined ? { version_id: versionId } : {}),
    }),
  });
}

// ---------------------------------------------------------------------------
// Security Scan
// ---------------------------------------------------------------------------

export interface SecurityFinding {
  severity: 'critical' | 'high' | 'medium' | 'low';
  category: string;
  file: string;
  line: number;
  description: string;
  code_snippet: string;
}

export interface SecurityScanResult {
  safe: boolean;
  findings: SecurityFinding[];
  summary: string;
  version_id: number;
  session_id: number;
}

export interface SecurityFixResult {
  success: boolean;
  previewUrl: string;
  versionId: number;
  versionNumber: number;
  themeSlug: string;
}

/**
 * Run an AI-powered security scan on theme files.
 */
export async function securityScan(
  sessionId: number,
  versionId?: number,
): Promise<SecurityScanResult> {
  return apiFetch<SecurityScanResult>('security-scan', {
    method: 'POST',
    body: JSON.stringify({
      session_id: sessionId,
      ...(versionId !== undefined ? { version_id: versionId } : {}),
    }),
  });
}

/**
 * Fix security issues in theme files using the AI.
 */
export async function securityFix(
  sessionId: number,
  versionId: number,
  findings: SecurityFinding[],
): Promise<SecurityFixResult> {
  return apiFetch<SecurityFixResult>('security-fix', {
    method: 'POST',
    body: JSON.stringify({
      session_id: sessionId,
      version_id: versionId,
      findings,
    }),
  });
}

// ---------------------------------------------------------------------------
// Figma Integration
// ---------------------------------------------------------------------------

/**
 * Save a Figma Personal Access Token.
 */
export async function saveFigmaConfig(
  token: string,
): Promise<{ success: boolean }> {
  return apiFetch<{ success: boolean }>('figma-config', {
    method: 'POST',
    body: JSON.stringify({ token }),
  });
}

/**
 * Test the Figma connection using the stored token.
 */
export async function testFigmaConnection(): Promise<{
  connected: boolean;
  userName: string;
}> {
  return apiFetch<{ connected: boolean; userName: string }>('figma-test', {
    method: 'POST',
    body: JSON.stringify({}),
  });
}

/**
 * Disconnect Figma by removing the stored token.
 */
export async function disconnectFigma(): Promise<{ success: boolean }> {
  return apiFetch<{ success: boolean }>('figma-disconnect', {
    method: 'POST',
    body: JSON.stringify({}),
  });
}

/**
 * Fetch available frames from a Figma file URL.
 */
export async function fetchFigmaFrames(
  fileUrl: string,
): Promise<{ fileName: string; frames: FigmaFrame[] }> {
  return apiFetch<{ fileName: string; frames: FigmaFrame[] }>('figma-frames', {
    method: 'POST',
    body: JSON.stringify({ file_url: fileUrl }),
  });
}

/**
 * Fetch full Figma context (screenshot + design tokens) for selected frames.
 */
export async function fetchFigmaContext(
  fileUrl: string,
  frameIds: string[],
): Promise<FigmaContext> {
  return apiFetch<FigmaContext>('figma-context', {
    method: 'POST',
    body: JSON.stringify({ file_url: fileUrl, frame_ids: frameIds }),
  });
}

// ---------------------------------------------------------------------------
// Code Editor — Theme Files
// ---------------------------------------------------------------------------

export interface ThemeFileData {
  path: string;
  content: string;
}

/**
 * Fetch the current theme files for a session.
 */
export async function getThemeFiles(sessionId: number): Promise<ThemeFileData[]> {
  const params = new URLSearchParams();
  params.set('session_id', String(sessionId));
  const data = await apiFetch<{ files: ThemeFileData[] }>(`theme-files?${params.toString()}`);
  return data.files;
}

/**
 * Save edited theme files. Creates a new version and writes to disk.
 */
export async function saveThemeFiles(
  sessionId: number,
  files: ThemeFileData[],
): Promise<{ success: boolean; previewUrl: string; versionNumber: number }> {
  return apiFetch<{ success: boolean; previewUrl: string; versionNumber: number }>('save-files', {
    method: 'POST',
    body: JSON.stringify({ session_id: sessionId, files }),
  });
}
