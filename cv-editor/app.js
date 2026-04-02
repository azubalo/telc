(function () {
  'use strict';

  // ===== Constants =====
  const MM_TO_PX = 3.7795275591; // 1mm ≈ 3.78px at 96dpi
  const A4_WIDTH_MM = 210;
  const A4_HEIGHT_MM = 297;
  const A4_HEIGHT_PX = Math.round(A4_HEIGHT_MM * MM_TO_PX);

  // ===== State =====
  const state = {
    margins: { top: 20, bottom: 20, left: 20, right: 20 },
    font: {
      family: 'Roboto, Arial, sans-serif',
      size: 11,
      lineHeight: 1.5,
      letterSpacing: 0,
    },
    colors: {
      text: '#1a1a1a',
      accent: '#1a5276',
      heading: '#1a5276',
    },
    headingSizes: { h1: 22, h2: 14 },
    apiKey: localStorage.getItem('ats-api-key') || '',
  };

  // ===== DOM References =====
  const $ = (sel) => document.querySelector(sel);
  const $$ = (sel) => document.querySelectorAll(sel);
  const pagesContainer = $('#pages-container');

  // ===== Default CV Template =====
  const defaultCV = `<h1>Your Full Name</h1>
<p class="contact-line">City, Country | +49 123 456 7890 | email@example.com | linkedin.com/in/yourname</p>

<h2>Professional Summary</h2>
<p>Results-driven professional with X+ years of experience in [industry/field]. Proven track record in [key achievement area]. Seeking to leverage expertise in [skill area] to contribute to [target company/role type].</p>

<h2>Work Experience</h2>
<div class="section-item">
  <div class="item-header">
    <span class="item-title">Job Title — Company Name</span>
    <span class="item-date">Jan 2022 – Present</span>
  </div>
  <ul>
    <li>Led a cross-functional team of X members to deliver [project], resulting in [quantifiable outcome]</li>
    <li>Developed and implemented [strategy/system] that improved [metric] by X%</li>
    <li>Collaborated with stakeholders to [action], achieving [result]</li>
  </ul>
</div>
<div class="section-item">
  <div class="item-header">
    <span class="item-title">Previous Job Title — Previous Company</span>
    <span class="item-date">Jun 2019 – Dec 2021</span>
  </div>
  <ul>
    <li>Managed [responsibility] for [scope], contributing to [outcome]</li>
    <li>Spearheaded [initiative] that reduced [metric] by X%</li>
    <li>Trained and mentored X junior team members</li>
  </ul>
</div>

<h2>Education</h2>
<div class="section-item">
  <div class="item-header">
    <span class="item-title">Degree Title — University Name</span>
    <span class="item-date">2015 – 2019</span>
  </div>
  <p>Relevant coursework: Course 1, Course 2, Course 3</p>
</div>

<h2>Skills</h2>
<p><strong>Technical:</strong> Skill 1, Skill 2, Skill 3, Skill 4, Skill 5</p>
<p><strong>Languages:</strong> English (Native), German (B2), French (A2)</p>
<p><strong>Tools:</strong> Tool 1, Tool 2, Tool 3</p>

<h2>Certifications</h2>
<ul>
  <li>Certification Name — Issuing Organization (Year)</li>
  <li>Certification Name — Issuing Organization (Year)</li>
</ul>`;

  // ===== Page Management =====
  function createPage(pageNum) {
    const page = document.createElement('div');
    page.className = 'a4-page';
    page.dataset.page = pageNum;

    const content = document.createElement('div');
    content.className = 'page-content';
    content.contentEditable = 'true';
    content.spellcheck = true;

    page.appendChild(content);

    const pageLabel = document.createElement('div');
    pageLabel.className = 'page-number';
    pageLabel.textContent = `Page ${pageNum}`;
    page.appendChild(pageLabel);

    return page;
  }

  function initEditor() {
    const page = createPage(1);
    pagesContainer.innerHTML = '';
    pagesContainer.appendChild(page);
    const content = page.querySelector('.page-content');
    content.innerHTML = defaultCV;
    applyStyles();
    setupContentListeners(content);
    content.focus();
  }

  function setupContentListeners(content) {
    content.addEventListener('input', debounce(handleOverflow, 150));
    content.addEventListener('keydown', handleKeydown);
    content.addEventListener('paste', handlePaste);
  }

  // ===== Overflow / Pagination =====
  function handleOverflow() {
    const pages = $$('.a4-page');
    const marginTopPx = state.margins.top * MM_TO_PX;
    const marginBottomPx = state.margins.bottom * MM_TO_PX;
    const usableHeight = A4_HEIGHT_PX - marginTopPx - marginBottomPx;

    // Process each page: if content overflows, move excess to next page
    for (let i = 0; i < pages.length; i++) {
      const content = pages[i].querySelector('.page-content');
      if (!content) continue;

      // Check if content exceeds usable height
      if (content.scrollHeight > usableHeight + 2) {
        // Need to move some content to next page
        let nextPage = pages[i + 1];
        if (!nextPage) {
          nextPage = createPage(i + 2);
          pagesContainer.appendChild(nextPage);
          const nextContent = nextPage.querySelector('.page-content');
          applyStylesToContent(nextContent);
          setupContentListeners(nextContent);
        }

        const nextContent = nextPage.querySelector('.page-content');
        moveOverflowContent(content, nextContent, usableHeight);
      }
    }

    // Clean up empty trailing pages (keep at least 1)
    cleanEmptyPages();
    updatePageNumbers();
  }

  function moveOverflowContent(fromContent, toContent, maxHeight) {
    const children = Array.from(fromContent.childNodes);
    const nodesToMove = [];

    // Find nodes that overflow
    for (let i = children.length - 1; i >= 0; i--) {
      const child = children[i];
      // Check if removing this node brings us within bounds
      nodesToMove.unshift(child);
      fromContent.removeChild(child);

      if (fromContent.scrollHeight <= maxHeight + 2) {
        break;
      }
    }

    // Prepend overflow nodes to next page
    const fragment = document.createDocumentFragment();
    nodesToMove.forEach((n) => fragment.appendChild(n));

    if (toContent.firstChild) {
      toContent.insertBefore(fragment, toContent.firstChild);
    } else {
      toContent.appendChild(fragment);
    }
  }

  function cleanEmptyPages() {
    const pages = Array.from($$('.a4-page'));
    for (let i = pages.length - 1; i > 0; i--) {
      const content = pages[i].querySelector('.page-content');
      if (content && content.textContent.trim() === '' && content.children.length === 0) {
        pages[i].remove();
      } else {
        break;
      }
    }
  }

  function updatePageNumbers() {
    const pages = $$('.a4-page');
    pages.forEach((page, idx) => {
      const label = page.querySelector('.page-number');
      if (label) label.textContent = `Page ${idx + 1}`;
      page.dataset.page = idx + 1;
    });
  }

  // ===== Style Application =====
  function applyStyles() {
    $$('.page-content').forEach(applyStylesToContent);
    $$('.a4-page').forEach((page) => {
      page.style.padding = `${state.margins.top}mm ${state.margins.right}mm ${state.margins.bottom}mm ${state.margins.left}mm`;
    });
    // Recheck overflow after style changes
    setTimeout(handleOverflow, 50);
  }

  function applyStylesToContent(content) {
    content.style.fontFamily = state.font.family;
    content.style.fontSize = state.font.size + 'pt';
    content.style.lineHeight = state.font.lineHeight;
    content.style.letterSpacing = state.font.letterSpacing + 'px';
    content.style.color = state.colors.text;

    // Apply heading styles
    content.querySelectorAll('h1').forEach((h1) => {
      h1.style.fontSize = state.headingSizes.h1 + 'pt';
      h1.style.color = state.colors.heading;
    });
    content.querySelectorAll('h2').forEach((h2) => {
      h2.style.fontSize = state.headingSizes.h2 + 'pt';
      h2.style.color = state.colors.heading;
      h2.style.borderBottomColor = state.colors.accent;
    });
  }

  // ===== Sidebar Controls =====
  function initControls() {
    // Margin sliders
    ['top', 'bottom', 'left', 'right'].forEach((side) => {
      const slider = $(`#margin-${side}`);
      slider.addEventListener('input', () => {
        state.margins[side] = parseInt(slider.value);
        $(`.slider-val[data-for="margin-${side}"]`).textContent = slider.value + 'mm';
        applyStyles();
      });
    });

    // Font family
    $('#font-family').addEventListener('change', (e) => {
      state.font.family = e.target.value;
      applyStyles();
    });

    // Font size
    setupSlider('font-size', (val) => {
      state.font.size = parseFloat(val);
      return val + 'pt';
    });

    // Line height
    setupSlider('line-height', (val) => {
      state.font.lineHeight = parseFloat(val);
      return parseFloat(val).toFixed(2);
    });

    // Letter spacing
    setupSlider('letter-spacing', (val) => {
      state.font.letterSpacing = parseFloat(val);
      return val + 'px';
    });

    // Heading sizes
    setupSlider('h1-size', (val) => {
      state.headingSizes.h1 = parseInt(val);
      return val + 'pt';
    });

    setupSlider('h2-size', (val) => {
      state.headingSizes.h2 = parseInt(val);
      return val + 'pt';
    });

    // Colors
    $('#text-color').addEventListener('input', (e) => {
      state.colors.text = e.target.value;
      applyStyles();
    });

    $('#accent-color').addEventListener('input', (e) => {
      state.colors.accent = e.target.value;
      applyStyles();
    });

    $('#heading-color').addEventListener('input', (e) => {
      state.colors.heading = e.target.value;
      applyStyles();
    });

    // API key persistence
    const apiKeyInput = $('#api-key');
    apiKeyInput.value = state.apiKey;
    apiKeyInput.addEventListener('change', (e) => {
      state.apiKey = e.target.value;
      localStorage.setItem('ats-api-key', e.target.value);
    });
  }

  function setupSlider(id, updateFn) {
    const slider = $(`#${id}`);
    slider.addEventListener('input', () => {
      const displayVal = updateFn(slider.value);
      $(`.slider-val[data-for="${id}"]`).textContent = displayVal;
      applyStyles();
    });
  }

  // ===== Toolbar Formatting =====
  function initToolbar() {
    $('#btn-bold').addEventListener('click', () => execCmd('bold'));
    $('#btn-italic').addEventListener('click', () => execCmd('italic'));
    $('#btn-underline').addEventListener('click', () => execCmd('underline'));
    $('#btn-align-left').addEventListener('click', () => execCmd('justifyLeft'));
    $('#btn-align-center').addEventListener('click', () => execCmd('justifyCenter'));
    $('#btn-align-right').addEventListener('click', () => execCmd('justifyRight'));
    $('#btn-align-justify').addEventListener('click', () => execCmd('justifyFull'));
    $('#btn-bullet').addEventListener('click', () => execCmd('insertUnorderedList'));
    $('#btn-ordered').addEventListener('click', () => execCmd('insertOrderedList'));

    $('#btn-undo').addEventListener('click', () => execCmd('undo'));
    $('#btn-redo').addEventListener('click', () => execCmd('redo'));

    // ATS panel toggle
    $('#btn-ats-check').addEventListener('click', toggleATSPanel);
    $('#btn-close-ats').addEventListener('click', toggleATSPanel);

    // ATS run
    $('#btn-run-ats').addEventListener('click', runATSCheck);

    // PDF download
    $('#btn-download').addEventListener('click', downloadPDF);

    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
      if (e.ctrlKey || e.metaKey) {
        switch (e.key.toLowerCase()) {
          case 'b': e.preventDefault(); execCmd('bold'); break;
          case 'i': e.preventDefault(); execCmd('italic'); break;
          case 'u': e.preventDefault(); execCmd('underline'); break;
          case 's': e.preventDefault(); downloadPDF(); break;
        }
      }
    });

    // Update active states on selection change
    document.addEventListener('selectionchange', updateToolbarState);
  }

  function execCmd(command, value) {
    document.execCommand(command, false, value || null);
    updateToolbarState();
  }

  function updateToolbarState() {
    $('#btn-bold').classList.toggle('active', document.queryCommandState('bold'));
    $('#btn-italic').classList.toggle('active', document.queryCommandState('italic'));
    $('#btn-underline').classList.toggle('active', document.queryCommandState('underline'));
  }

  // ===== ATS Panel =====
  function toggleATSPanel() {
    const panel = $('#ats-panel');
    panel.classList.toggle('hidden');
    $('#btn-ats-check').classList.toggle('active', !panel.classList.contains('hidden'));
  }

  function getCVText() {
    const contents = $$('.page-content');
    let text = '';
    contents.forEach((c) => {
      text += c.innerText + '\n';
    });
    return text.trim();
  }

  async function runATSCheck() {
    const jobDesc = $('#job-description').value.trim();
    const apiKey = state.apiKey || $('#api-key').value.trim();

    if (!jobDesc) {
      alert('Please paste a job description first.');
      return;
    }
    if (!apiKey) {
      alert('Please enter your Claude API key.');
      return;
    }

    const cvText = getCVText();
    if (!cvText || cvText.length < 50) {
      alert('Your CV seems too short. Please add more content.');
      return;
    }

    // Show loading
    $('#ats-results').classList.add('hidden');
    $('#ats-loading').classList.remove('hidden');
    $('#btn-run-ats').disabled = true;

    try {
      const result = await callClaudeAPI(apiKey, cvText, jobDesc);
      displayATSResults(result);
    } catch (err) {
      console.error('ATS Check Error:', err);
      alert('ATS Check failed: ' + err.message);
    } finally {
      $('#ats-loading').classList.add('hidden');
      $('#btn-run-ats').disabled = false;
    }
  }

  async function callClaudeAPI(apiKey, cvText, jobDesc) {
    const systemPrompt = `You are an expert ATS (Applicant Tracking System) analyzer specializing in Workday, Taleo, and IQVIA systems.

Analyze the provided CV against the job description. Return a JSON object with exactly this structure:
{
  "score": <number 0-100>,
  "matchedKeywords": ["keyword1", "keyword2", ...],
  "missingKeywords": ["keyword1", "keyword2", ...],
  "suggestions": [
    {
      "type": "Missing Skill" | "Phrasing" | "Format" | "Keyword",
      "current": "current text or empty",
      "suggested": "suggested improvement",
      "reason": "brief reason"
    }
  ]
}

Scoring criteria:
- Keyword match density (40%): How many required skills/keywords from the JD appear in the CV
- Phrasing alignment (25%): Whether the CV uses similar terminology as the JD
- Structure & ATS readability (20%): Clean formatting, standard section headers
- Quantifiable achievements (15%): Use of numbers, metrics, percentages

Be strict but fair. Most CVs score 40-75. Only exceptional matches score above 85.
Return ONLY the JSON object, no other text.`;

    const response = await fetch('https://api.anthropic.com/v1/messages', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'x-api-key': apiKey,
        'anthropic-version': '2023-06-01',
        'anthropic-dangerous-direct-browser-access': 'true',
      },
      body: JSON.stringify({
        model: 'claude-sonnet-4-20250514',
        max_tokens: 2048,
        system: systemPrompt,
        messages: [
          {
            role: 'user',
            content: `## CV Content:\n${cvText}\n\n## Job Description:\n${jobDesc}\n\nAnalyze this CV against the job description and return the JSON result.`,
          },
        ],
      }),
    });

    if (!response.ok) {
      const errBody = await response.text();
      throw new Error(`API Error (${response.status}): ${errBody}`);
    }

    const data = await response.json();
    const text = data.content[0].text;

    // Parse JSON from response (handle potential markdown wrapping)
    const jsonMatch = text.match(/\{[\s\S]*\}/);
    if (!jsonMatch) throw new Error('Invalid response format from API');
    return JSON.parse(jsonMatch[0]);
  }

  function displayATSResults(result) {
    const { score, matchedKeywords, missingKeywords, suggestions } = result;

    // Animate score ring
    const circumference = 2 * Math.PI * 54; // r=54
    const offset = circumference - (score / 100) * circumference;
    const circle = $('#score-circle');

    // Color based on score
    let color = '#ff6b6b'; // red
    if (score >= 70) color = '#4ecdc4'; // green
    else if (score >= 50) color = '#ffd93d'; // yellow

    circle.style.stroke = color;
    circle.style.strokeDashoffset = offset;
    $('#score-number').textContent = score;
    $('#score-number').style.color = color;

    // Missing keywords
    const missingEl = $('#missing-keywords');
    missingEl.innerHTML = '';
    (missingKeywords || []).forEach((kw) => {
      const tag = document.createElement('span');
      tag.className = 'tag missing';
      tag.textContent = kw;
      missingEl.appendChild(tag);
    });

    // Matched keywords
    const matchedEl = $('#matched-keywords');
    matchedEl.innerHTML = '';
    (matchedKeywords || []).forEach((kw) => {
      const tag = document.createElement('span');
      tag.className = 'tag matched';
      tag.textContent = kw;
      matchedEl.appendChild(tag);
    });

    // Suggestions
    const suggestionsEl = $('#suggestions-list');
    suggestionsEl.innerHTML = '';
    (suggestions || []).forEach((s) => {
      const card = document.createElement('div');
      card.className = 'suggestion-card';
      card.innerHTML = `
        <div class="suggestion-type">${escapeHtml(s.type)}</div>
        <div class="suggestion-text">${escapeHtml(s.reason)}</div>
        ${s.suggested ? `<div class="suggestion-fix">→ ${escapeHtml(s.suggested)}</div>` : ''}
      `;
      suggestionsEl.appendChild(card);
    });

    $('#ats-results').classList.remove('hidden');
  }

  // ===== PDF Export =====
  async function downloadPDF() {
    const btn = $('#btn-download');
    btn.disabled = true;
    btn.innerHTML = `
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
      </svg> Generating...`;

    try {
      // Clone the pages container for export
      const exportContainer = document.createElement('div');
      exportContainer.style.position = 'absolute';
      exportContainer.style.left = '-9999px';
      exportContainer.style.top = '0';
      document.body.appendChild(exportContainer);

      const pages = $$('.a4-page');
      pages.forEach((page) => {
        const clone = page.cloneNode(true);
        // Remove page number badges
        const badge = clone.querySelector('.page-number');
        if (badge) badge.remove();
        // Remove contenteditable
        const content = clone.querySelector('.page-content');
        if (content) content.removeAttribute('contenteditable');
        clone.style.boxShadow = 'none';
        clone.style.marginBottom = '0';
        exportContainer.appendChild(clone);
      });

      const opt = {
        margin: 0,
        filename: 'CV_ATS_Optimized.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: {
          scale: 2,
          useCORS: true,
          letterRendering: true,
          width: Math.round(A4_WIDTH_MM * MM_TO_PX),
        },
        jsPDF: {
          unit: 'mm',
          format: 'a4',
          orientation: 'portrait',
        },
        pagebreak: { mode: ['css', 'legacy'], before: '.a4-page' },
      };

      await html2pdf().set(opt).from(exportContainer).save();
      document.body.removeChild(exportContainer);
    } catch (err) {
      console.error('PDF export error:', err);
      alert('PDF export failed: ' + err.message);
    } finally {
      btn.disabled = false;
      btn.innerHTML = `
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
          <polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
        </svg> Download PDF`;
    }
  }

  // ===== Paste Handler (strip non-ATS formatting) =====
  function handlePaste(e) {
    e.preventDefault();
    const text = e.clipboardData.getData('text/plain');
    document.execCommand('insertText', false, text);
  }

  // ===== Keyboard Handler =====
  function handleKeydown(e) {
    // Tab key for indentation
    if (e.key === 'Tab') {
      e.preventDefault();
      if (e.shiftKey) {
        execCmd('outdent');
      } else {
        execCmd('indent');
      }
    }
  }

  // ===== Utility =====
  function debounce(fn, delay) {
    let timer;
    return function (...args) {
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, args), delay);
    };
  }

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  // ===== Save / Restore =====
  function saveToLocalStorage() {
    const pages = $$('.page-content');
    const htmlContent = Array.from(pages).map((p) => p.innerHTML);
    localStorage.setItem('cv-editor-content', JSON.stringify(htmlContent));
    localStorage.setItem('cv-editor-state', JSON.stringify(state));
  }

  function restoreFromLocalStorage() {
    const savedContent = localStorage.getItem('cv-editor-content');
    const savedState = localStorage.getItem('cv-editor-state');

    if (savedState) {
      try {
        const parsed = JSON.parse(savedState);
        Object.assign(state.margins, parsed.margins || {});
        Object.assign(state.font, parsed.font || {});
        Object.assign(state.colors, parsed.colors || {});
        Object.assign(state.headingSizes, parsed.headingSizes || {});

        // Update UI controls to match state
        ['top', 'bottom', 'left', 'right'].forEach((side) => {
          const slider = $(`#margin-${side}`);
          if (slider) {
            slider.value = state.margins[side];
            $(`.slider-val[data-for="margin-${side}"]`).textContent = state.margins[side] + 'mm';
          }
        });

        // Font controls
        const fontSelect = $('#font-family');
        if (fontSelect) {
          for (const opt of fontSelect.options) {
            if (opt.value === state.font.family) {
              opt.selected = true;
              break;
            }
          }
        }

        const setSlider = (id, val, suffix) => {
          const s = $(`#${id}`);
          if (s) {
            s.value = val;
            $(`.slider-val[data-for="${id}"]`).textContent = val + (suffix || '');
          }
        };

        setSlider('font-size', state.font.size, 'pt');
        setSlider('line-height', state.font.lineHeight.toFixed(2), '');
        setSlider('letter-spacing', state.font.letterSpacing, 'px');
        setSlider('h1-size', state.headingSizes.h1, 'pt');
        setSlider('h2-size', state.headingSizes.h2, 'pt');

        $('#text-color').value = state.colors.text;
        $('#accent-color').value = state.colors.accent;
        $('#heading-color').value = state.colors.heading;
      } catch (e) {
        console.warn('Failed to restore state:', e);
      }
    }

    if (savedContent) {
      try {
        const htmlArray = JSON.parse(savedContent);
        pagesContainer.innerHTML = '';
        htmlArray.forEach((html, idx) => {
          const page = createPage(idx + 1);
          pagesContainer.appendChild(page);
          const content = page.querySelector('.page-content');
          content.innerHTML = html;
          setupContentListeners(content);
        });
        applyStyles();
        return true;
      } catch (e) {
        console.warn('Failed to restore content:', e);
      }
    }
    return false;
  }

  // Auto-save every 5 seconds
  setInterval(saveToLocalStorage, 5000);

  // ===== Initialize =====
  function init() {
    initControls();
    initToolbar();

    const restored = restoreFromLocalStorage();
    if (!restored) {
      initEditor();
    }
  }

  // Wait for DOM
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
