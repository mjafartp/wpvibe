/**
 * MessageBubble — Renders a single chat message.
 *
 * Handles user and assistant message styles, simple markdown-like
 * rendering (bold, inline code, code blocks with copy button),
 * and a blinking cursor for streaming messages.
 */

import { useState, useCallback, type ReactNode } from 'react';
import type { ImageAttachment, Message } from '@/editor/types';

// ---------------------------------------------------------------------------
// Props
// ---------------------------------------------------------------------------

export interface MessageBubbleProps {
  message: Message | { role: 'assistant' | 'user'; content: string };
  isStreaming?: boolean;
}

// ---------------------------------------------------------------------------
// Simple content renderer
// ---------------------------------------------------------------------------

/**
 * Splits message content into segments: plain text, code blocks, bold,
 * and inline code. Returns an array of React nodes.
 *
 * Supported syntax:
 *   ```lang\ncode\n```  ->  code block
 *   `inline`            ->  inline code
 *   **bold**            ->  bold text
 *   Newlines            ->  <br />
 */
function renderContent(content: string): ReactNode[] {
  const nodes: ReactNode[] = [];

  // Split on fenced code blocks first: ```...```
  const codeBlockRegex = /```(\w*)\n?([\s\S]*?)```/g;
  let lastIndex = 0;
  let match: RegExpExecArray | null;

  while ((match = codeBlockRegex.exec(content)) !== null) {
    // Text before this code block
    if (match.index > lastIndex) {
      const before = content.slice(lastIndex, match.index);
      nodes.push(...renderInlineSegments(before, nodes.length));
    }

    const language = match[1] || 'code';
    const code = match[2];
    nodes.push(
      <CodeBlock key={`cb-${nodes.length}`} language={language} code={code} />,
    );

    lastIndex = match.index + match[0].length;
  }

  // Remaining text after the last code block
  if (lastIndex < content.length) {
    const remaining = content.slice(lastIndex);
    nodes.push(...renderInlineSegments(remaining, nodes.length));
  }

  return nodes;
}

/**
 * Renders inline formatting: **bold**, `inline code`, and newlines.
 */
function renderInlineSegments(
  text: string,
  keyOffset: number,
): ReactNode[] {
  const nodes: ReactNode[] = [];
  // Match **bold** or `inline code`
  const inlineRegex = /(\*\*(.+?)\*\*)|(`([^`]+?)`)/g;
  let lastIdx = 0;
  let inlineMatch: RegExpExecArray | null;

  while ((inlineMatch = inlineRegex.exec(text)) !== null) {
    // Plain text before match
    if (inlineMatch.index > lastIdx) {
      const plain = text.slice(lastIdx, inlineMatch.index);
      nodes.push(...textWithBreaks(plain, keyOffset + nodes.length));
    }

    if (inlineMatch[2]) {
      // Bold
      nodes.push(
        <strong key={`b-${keyOffset}-${nodes.length}`}>
          {inlineMatch[2]}
        </strong>,
      );
    } else if (inlineMatch[4]) {
      // Inline code
      nodes.push(
        <code
          key={`ic-${keyOffset}-${nodes.length}`}
          className="vb-inline-code"
        >
          {inlineMatch[4]}
        </code>,
      );
    }

    lastIdx = inlineMatch.index + inlineMatch[0].length;
  }

  // Remaining plain text
  if (lastIdx < text.length) {
    const remaining = text.slice(lastIdx);
    nodes.push(...textWithBreaks(remaining, keyOffset + nodes.length));
  }

  return nodes;
}

/**
 * Converts newlines in plain text to <br /> elements.
 */
function textWithBreaks(text: string, keyOffset: number): ReactNode[] {
  const lines = text.split('\n');
  const nodes: ReactNode[] = [];

  lines.forEach((line, i) => {
    if (i > 0) {
      nodes.push(<br key={`br-${keyOffset}-${i}`} />);
    }
    if (line) {
      nodes.push(line);
    }
  });

  return nodes;
}

// ---------------------------------------------------------------------------
// CodeBlock sub-component
// ---------------------------------------------------------------------------

interface CodeBlockProps {
  language: string;
  code: string;
}

function CodeBlock({ language, code }: CodeBlockProps) {
  const [copied, setCopied] = useState(false);

  const handleCopy = useCallback(async () => {
    try {
      await navigator.clipboard.writeText(code);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Fallback: use a hidden textarea and the Selection API
      const textarea = document.createElement('textarea');
      textarea.value = code;
      textarea.setAttribute('readonly', '');
      textarea.style.position = 'fixed';
      textarea.style.left = '-9999px';
      document.body.appendChild(textarea);
      textarea.select();
      try {
        document.execCommand('copy');
      } catch {
        // Copy failed silently
      }
      document.body.removeChild(textarea);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }
  }, [code]);

  return (
    <div className="vb-code-block">
      <div className="vb-code-block-header">
        <span>{language}</span>
        <button
          type="button"
          className="vb-copy-button"
          onClick={handleCopy}
          aria-label={copied ? 'Copied' : 'Copy code'}
        >
          {copied ? (
            <>
              {/* Checkmark icon */}
              <svg
                width="12"
                height="12"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <polyline points="20 6 9 17 4 12" />
              </svg>
              Copied
            </>
          ) : (
            <>
              {/* Copy icon */}
              <svg
                width="12"
                height="12"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <rect x="9" y="9" width="13" height="13" rx="2" ry="2" />
                <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1" />
              </svg>
              Copy
            </>
          )}
        </button>
      </div>
      <pre>
        <code>{code}</code>
      </pre>
    </div>
  );
}

// ---------------------------------------------------------------------------
// MessageBubble component
// ---------------------------------------------------------------------------

export function MessageBubble({ message, isStreaming = false }: MessageBubbleProps) {
  const isUser = message.role === 'user';
  const isAssistant = message.role === 'assistant';
  const hasAttachments =
    'attachments' in message &&
    Array.isArray(message.attachments) &&
    message.attachments.length > 0;

  return (
    <div
      className={`vb-flex vb-w-full vb-mb-4 ${isUser ? 'vb-justify-end' : 'vb-justify-start'}`}
    >
      <div
        className={`vb-max-w-[85%] vb-rounded-2xl vb-px-4 vb-py-3 vb-text-sm vb-leading-relaxed ${
          isUser
            ? 'vb-bg-indigo-600 vb-text-white vb-rounded-br-sm'
            : 'vb-message-assistant vb-bg-white vb-text-slate-800 vb-rounded-bl-sm vb-border vb-border-slate-200 vb-shadow-sm'
        }`}
      >
        <div className="vb-message-content">
          {renderContent(message.content)}
          {isStreaming && isAssistant && (
            <span className="vb-streaming-cursor" />
          )}
          {hasAttachments && (
            <div className="vb-flex vb-gap-2 vb-mt-2 vb-flex-wrap">
              {(message as Message).attachments!
                .filter((a): a is ImageAttachment => a.type === 'image')
                .map((img) => (
                  <a
                    key={img.id}
                    href={img.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="vb-block vb-w-20 vb-h-20 vb-rounded-lg vb-overflow-hidden vb-border vb-border-slate-200 hover:vb-border-indigo-300 vb-transition-colors"
                  >
                    <img
                      src={img.thumbnailUrl || img.url}
                      alt=""
                      className="vb-w-full vb-h-full vb-object-cover"
                    />
                  </a>
                ))}
              {(message as Message).attachments!
                .filter((a) => a.type === 'figma')
                .map((a, i) => (
                  <div
                    key={`figma-${i}`}
                    className="vb-flex vb-items-center vb-gap-1.5 vb-px-2.5 vb-py-1 vb-rounded-lg vb-border vb-border-indigo-200 vb-bg-indigo-50 vb-text-xs vb-text-indigo-600"
                  >
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      width="12"
                      height="12"
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="currentColor"
                      strokeWidth="2"
                      strokeLinecap="round"
                      strokeLinejoin="round"
                    >
                      <circle cx="12" cy="12" r="10" />
                    </svg>
                    Figma frame attached
                  </div>
                ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
