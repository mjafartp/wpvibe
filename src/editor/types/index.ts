/**
 * Shared TypeScript interfaces for WPVibe.
 */

// --- API Key Types ---

export type KeyType =
  | 'wpvibe_service'
  | 'claude_api'
  | 'claude_oauth'
  | 'openai_codex'
  | 'unknown';

export interface KeyValidationResult {
  valid: boolean;
  message: string;
  keyType: KeyType;
  models: Model[];
}

export interface Model {
  id: string;
  name: string;
  description: string;
  recommended: boolean;
}

// --- Sessions ---

export interface Session {
  id: number;
  userId: number;
  sessionName: string;
  themeSlug?: string;
  modelUsed: string;
  createdAt: string;
  updatedAt: string;
}

// --- Messages ---

export interface Message {
  id: number;
  sessionId: number;
  role: 'user' | 'assistant' | 'system';
  content: string;
  attachments?: Attachment[];
  tokenCount?: number;
  createdAt: string;
}

export type Attachment = ImageAttachment | FigmaAttachment;

export interface ImageAttachment {
  type: 'image';
  id: string;
  url: string;
  mediaType: string;
  base64?: string;
  thumbnailUrl?: string;
}

export interface FigmaAttachment {
  type: 'figma';
  context: FigmaContext;
}

// --- Theme ---

export interface ThemeFile {
  path: string;
  content: string;
}

export interface ThemeVersion {
  id: number;
  sessionId: number;
  versionNumber: number;
  themeSlug: string;
  filesSnapshot: ThemeFile[];
  messageId: number;
  appliedAt?: string;
  createdAt: string;
  previewUrl?: string;
}

// --- AI ---

export interface AIRequest {
  messages: Message[];
  model: string;
  maxTokens: number;
  stream: boolean;
  system?: string;
  attachments?: ImageAttachment[];
  figmaContext?: FigmaContext;
}

export interface AIStreamChunk {
  type: 'text_delta' | 'theme_update' | 'error' | 'done';
  content?: string;
  themeUpdate?: {
    versionId: number;
    previewUrl: string;
    filesChanged: string[];
    themeSlug: string;
    versionNumber: number;
    totalVersions: number;
  };
  error?: string;
  sessionId?: number;
  messageId?: number;
}

export interface AIResponse {
  message: string;
  files: ThemeFile[];
  previewHtml: string;
  changesSummary: string[];
}

// --- Figma ---

export interface FigmaContext {
  fileName: string;
  frameName: string;
  frameImageUrl: string;
  designTokens: {
    colors: Record<string, string>;
    typography: Record<string, string>;
    spacing: Record<string, string>;
  };
  componentTree: Record<string, unknown>;
}

export interface FigmaFrame {
  id: string;
  name: string;
  pageName: string;
  type: string;
  thumbnailUrl?: string;
}

// --- Section Selection (preview click-to-edit) ---

export interface SelectedSection {
  /** CSS selector path to the element (e.g. "header > nav") */
  selector: string;
  /** Tag name (e.g. "SECTION", "NAV", "DIV") */
  tagName: string;
  /** Short readable label (e.g. "Hero Section", "Navigation") */
  label: string;
  /** The element's outerHTML truncated for context */
  outerHtmlSnippet: string;
}

// --- WordPress Bridge ---

export interface WPVibeData {
  restUrl: string;
  nonce: string;
  hasKey: boolean;
  hasFigma: boolean;
  keyType: KeyType;
  selectedModel: string;
  onboardingComplete: boolean;
  adminUrl: string;
  pluginUrl: string;
  cssFramework?: string;
}

declare global {
  interface Window {
    wpvibeData: WPVibeData;
  }
}
