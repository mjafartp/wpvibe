/**
 * MessageList — Scrollable container for chat messages.
 *
 * Renders all messages from the current session, plus a streaming
 * message bubble when the AI is generating a response. Auto-scrolls
 * to the bottom when new content arrives.
 */

import { useRef, useEffect } from 'react';
import { useChat } from '@/editor/hooks/useChat';
import { useEditorStore } from '@/editor/store/editorStore';
import type { StreamingPhase } from '@/editor/store/editorStore';
import { MessageBubble } from './MessageBubble';

const PHASE_LABELS: Record<StreamingPhase, string> = {
  thinking: 'Thinking...',
  generating: 'Generating theme code...',
  building: 'Building preview...',
  done: 'Done!',
};

function StreamingIndicator({ phase }: { phase: StreamingPhase | null }) {
  const label = phase ? PHASE_LABELS[phase] : 'Thinking...';

  return (
    <div className="vb-flex vb-justify-start vb-mb-4">
      <div className="vb-bg-white vb-border vb-border-slate-200 vb-shadow-sm vb-rounded-2xl vb-rounded-bl-sm vb-px-4 vb-py-3">
        <div className="vb-flex vb-items-center vb-gap-2">
          <div className="vb-typing-indicator">
            <span className="vb-typing-dot" />
            <span className="vb-typing-dot" />
            <span className="vb-typing-dot" />
          </div>
          <span className="vb-text-sm vb-text-slate-500">{label}</span>
        </div>
      </div>
    </div>
  );
}

export function MessageList() {
  const { messages, isStreaming, streamingContent } = useChat();
  const streamingPhase = useEditorStore((s) => s.streamingPhase);
  const bottomRef = useRef<HTMLDivElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);

  // Auto-scroll to bottom when messages change or streaming state updates
  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages, streamingContent, isStreaming, streamingPhase]);

  const hasMessages = messages.length > 0 || isStreaming;

  return (
    <div
      ref={containerRef}
      className="vb-flex-1 vb-overflow-y-auto vb-px-4 vb-py-4"
    >
      {!hasMessages && (
        <div className="vb-flex vb-flex-col vb-items-center vb-justify-center vb-h-full vb-text-center vb-px-8">
          {/* Welcome state */}
          <div className="vb-mb-4 vb-text-slate-300">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              width="48"
              height="48"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.5"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M12 2L2 7l10 5 10-5-10-5z" />
              <path d="M2 17l10 5 10-5" />
              <path d="M2 12l10 5 10-5" />
            </svg>
          </div>
          <h3 className="vb-text-lg vb-font-semibold vb-text-slate-600 vb-mb-2">
            Start building your theme
          </h3>
          <p className="vb-text-sm vb-text-slate-400 vb-max-w-sm">
            Describe the WordPress theme you want to create. You can specify
            colors, layouts, typography, and any design details.
          </p>
        </div>
      )}

      {/* Rendered messages */}
      {messages.map((msg) => (
        <MessageBubble key={msg.id} message={msg} />
      ))}

      {/* Streaming phase indicator — shows progress labels instead of raw JSON */}
      {isStreaming && <StreamingIndicator phase={streamingPhase} />}

      {/* Scroll anchor */}
      <div ref={bottomRef} />
    </div>
  );
}
