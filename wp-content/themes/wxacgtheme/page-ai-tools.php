<?php
/**
 * Template Name: AI 工具整合
 *
 * 訪問路徑：/ai-tools/ 或自訂 slug（例如 /ai/）
 * Path: wp-content/themes/blocksy-child/page-ai-tools.php
 *
 * @version 2.3.0
 * @since   2026-05-25
 *
 * Changelog:
 *   2.3.0 (2026-06-22) 新增 ItemList JSON-LD schema（SEO 強化）、修正跑馬燈無縫接續邏輯、縮圖正則容錯
 *   2.2.0 (2026-05-25) Hero 緊湊化（統計內嵌單行）、新增最新情報跑馬燈、新聞區移至工具牆上方
 *   2.1.0 (2026-05-25) 移除「本月焦點」、Hero 高度調矮
 *   2.0.0 (2026-05-25) 大改版：16 大類、50+ 工具、AI Agent 焦點區、CSS 拆出
 *   1.0.0 (2026-05-16) 初版
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// 防止登入狀態被頁面快取餵到訪客版（針對登入者停用本頁快取）
if ( is_user_logged_in() && ! defined( 'DONOTCACHEPAGE' ) ) {
    define( 'DONOTCACHEPAGE', true );
}

get_header();
/* ============================================================
 * 🛠️ AI 工具完整資料庫（v3.0 - 75+ 工具，持續更新）
 * ------------------------------------------------------------
 * 最後更新：2026-05-25
 * 涵蓋：繪圖、寫作、語音、影片、音樂、角色、3D、動畫、
 *      聊天助理、Agent、程式、搜尋、生產力、翻譯、設計、教育
 * ============================================================ */
$ai_tools = [

    /* ═══════════ 🎨 AI 繪圖（10 個） ═══════════ */
    [ 'icon' => '🎨', 'bg' => 'rgba(139,92,246,0.15)', 'name' => 'NovelAI',
      'tag' => '動漫風格繪圖王者', 'desc' => '專為動漫風格設計的 AI 繪圖工具，精準掌握二次元美學，支援角色設計與場景生成，台灣動漫圈最常用之一。',
      'category' => 'drawing', 'badge' => 'paid', 'badge_text' => '付費', 'url' => 'https://novelai.net/' ],
    [ 'icon' => '✨', 'bg' => 'rgba(59,130,246,0.15)', 'name' => 'Stable Diffusion',
      'tag' => '開源繪圖生態系', 'desc' => '最強大的開源 AI 繪圖模型，搭配 Anything V5、Pony、Illustrious 等模型，可免費本地部署、自由訓練 LoRA。',
      'category' => 'drawing', 'badge' => 'free', 'badge_text' => '免費', 'url' => 'https://stability.ai/' ],
    [ 'icon' => '🖌️', 'bg' => 'rgba(14,165,233,0.15)', 'name' => 'Midjourney',
      'tag' => '藝術質感插畫', 'desc' => '生成質感極高的藝術風插畫，v7 支援動漫風格與角色一致性參考，社群作品庫龐大，創作者參考首選。',
      'category' => 'drawing', 'badge' => 'paid', 'badge_text' => '付費', 'url' => 'https://www.midjourney.com/' ],
    [ 'icon' => '🌈', 'bg' => 'rgba(244,114,182,0.15)', 'name' => 'DALL-E 3',
      'tag' => 'OpenAI 繪圖', 'desc' => 'OpenAI 出品的繪圖模型，理解 prompt 能力極強，內建於 ChatGPT，適合精準描述需求的場景。',
      'category' => 'drawing', 'badge' => 'freemium', 'badge_text' => '部分免費', 'url' => 'https://openai.com/dall-e-3' ],
    [ 'icon' => '🎭', 'bg' => 'rgba(168,85,247,0.15)', 'name' => 'Leonardo.AI',
      'tag' => '遊戲美術專用', 'desc' => '為遊戲與動漫資產設計的 AI 平台，提供大量微調模型，每日免費點數，適合創作工作流程。',
      'category' => 'drawing', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://leonardo.ai/' ],
    [ 'icon' => '🌸', 'bg' => 'rgba(236,72,153,0.15)', 'name' => 'Tensor.Art',
      'tag' => '線上動漫繪圖社群', 'desc' => '免本地部署的 SD 雲端平台，動漫風模型庫龐大，每日免費點數，台日動漫創作者活躍社群。',
      'category' => 'drawing', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://tensor.art/' ],
    [ 'icon' => '🌊', 'bg' => 'rgba(34,197,94,0.15)', 'name' => 'Krea AI',
      'tag' => '即時生成繪圖', 'desc' => '邊畫邊生成的即時 AI 繪圖工具，可結合手繪、3D 草模、照片改造畫面，設計師工作流首選。',
      'category' => 'drawing', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://www.krea.ai/' ],
    [ 'icon' => '🖼️', 'bg' => 'rgba(251,146,60,0.15)', 'name' => 'Ideogram',
      'tag' => 'AI 文字海報', 'desc' => '業界唯一精準生成圖中文字的 AI 繪圖工具，海報、Logo、招牌、漫畫對白氣泡都能準確輸出文字。',
      'category' => 'drawing', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://ideogram.ai/' ],
    [ 'icon' => '🎯', 'bg' => 'rgba(168,85,247,0.15)', 'name' => 'Recraft',
      'tag' => 'AI 向量繪圖', 'desc' => '專注生成可編輯的 SVG 向量圖，適合 Logo、Icon、插畫設計，輸出可直接用於印刷與商用。',
      'category' => 'drawing', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://www.recraft.ai/' ],
    [ 'icon' => '🧩', 'bg' => 'rgba(75,85,99,0.18)', 'name' => 'ComfyUI',
      'tag' => 'SD 節點式工作流', 'desc' => 'Stable Diffusion 的進階介面，節點式拖拉建立繪圖工作流，重度創作者必備。',
      'category' => 'drawing', 'badge' => 'free', 'badge_text' => '免費', 'url' => 'https://www.comfy.org/' ],

    /* ═══════════ 📝 AI 寫作（6 個） ═══════════ */
    [ 'icon' => '🧠', 'bg' => 'rgba(217,119,6,0.15)', 'name' => 'Claude',
      'tag' => 'Anthropic 創意寫作', 'desc' => 'Anthropic 出品的頂級 AI 助理，擅長長文創作、劇本生成、角色對白，繁體中文能力業界最強，Artifacts 可即時預覽。',
      'category' => 'writing', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://claude.ai/' ],
    [ 'icon' => '💬', 'bg' => 'rgba(16,185,129,0.15)', 'name' => 'ChatGPT',
      'tag' => 'OpenAI 全能助理', 'desc' => 'OpenAI 旗艦對話 AI，內建繪圖、語音、Canvas 寫作畫布、深度研究模式等完整生態。',
      'category' => 'writing', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://chatgpt.com/' ],
    [ 'icon' => '📓', 'bg' => 'rgba(75,85,99,0.18)', 'name' => 'Notion AI',
      'tag' => '筆記內整合寫作', 'desc' => '直接內建於 Notion 筆記中的 AI，可摘要、續寫、翻譯、生成大綱，整合工作流程順手。',
      'category' => 'writing', 'badge' => 'paid', 'badge_text' => '付費', 'url' => 'https://www.notion.so/product/ai' ],
    [ 'icon' => '🪶', 'bg' => 'rgba(99,102,241,0.15)', 'name' => 'Jasper',
      'tag' => '行銷文案專用', 'desc' => '商業導向 AI 寫作平台，內建大量行銷模板（廣告文案、品牌故事、社群貼文），歐美企業愛用。',
      'category' => 'writing', 'badge' => 'paid', 'badge_text' => '付費', 'url' => 'https://www.jasper.ai/' ],
    [ 'icon' => '✍️', 'bg' => 'rgba(244,63,94,0.15)', 'name' => 'Sudowrite',
      'tag' => '小說創作專用', 'desc' => '專為小說家設計的 AI 寫作工具，內建劇情推進、角色描述、世界觀建構等功能，輕小說作者愛用。',
      'category' => 'writing', 'badge' => 'paid', 'badge_text' => '付費', 'url' => 'https://www.sudowrite.com/' ],
    [ 'icon' => '📰', 'bg' => 'rgba(59,130,246,0.15)', 'name' => 'Copy.ai',
      'tag' => '自動化內容生成', 'desc' => '一鍵生成行銷文案、部落格文章、Email 序列，支援多步驟工作流自動化，內容創作者好幫手。',
      'category' => 'writing', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://www.copy.ai/' ],

    /* ═══════════ 🎙️ AI 語音（5 個） ═══════════ */
    [ 'icon' => '🎙️', 'bg' => 'rgba(249,115,22,0.15)', 'name' => 'ElevenLabs',
      'tag' => '業界頂級配音', 'desc' => '業界第一的 AI 語音生成，可複製聲優音色、生成高品質配音，支援 32 種語言含繁中。',
      'category' => 'voice', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://elevenlabs.io/' ],
    [ 'icon' => '🔊', 'bg' => 'rgba(20,184,166,0.15)', 'name' => 'Murf.ai',
      'tag' => '商用旁白配音', 'desc' => '商用級語音合成平台，提供 200+ 真人風聲音，適合製作 YouTube 旁白、podcast、教學影片。',
      'category' => 'voice', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://murf.ai/' ],
    [ 'icon' => '🎤', 'bg' => 'rgba(239,68,68,0.15)', 'name' => 'Voicemod',
      'tag' => '即時變聲', 'desc' => '即時 AI 變聲工具，遊戲直播、VTuber 開播都能用，內建大量動漫角色音色。',
      'category' => 'voice', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://www.voicemod.net/' ],
    [ 'icon' => '🎧', 'bg' => 'rgba(168,85,247,0.15)', 'name' => 'Suno Bark',
      'tag' => '開源語音合成', 'desc' => '開源的多語言文字轉語音模型，可生成笑聲、嘆氣、唱歌等情緒表達，創作者免費可用。',
      'category' => 'voice', 'badge' => 'free', 'badge_text' => '免費', 'url' => 'https://github.com/suno-ai/bark' ],
    [ 'icon' => '🗣️', 'bg' => 'rgba(34,197,94,0.15)', 'name' => 'Hume AI',
      'tag' => '情感語音 AI', 'desc' => '具備情緒識別與情緒語音生成能力的 AI，能根據情境用喜怒哀樂不同語氣對話，互動體驗極佳。',
      'category' => 'voice', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://www.hume.ai/' ],

    /* ═══════════ 🎬 AI 影片（10 個） ═══════════ */
    [ 'icon' => '🌟', 'bg' => 'rgba(34,197,94,0.15)', 'name' => 'Sora',
      'tag' => 'OpenAI 影片模型', 'desc' => 'OpenAI 的旗艦影片生成模型，可生成最長 20 秒高品質影片，畫面一致性與物理擬真業界頂級。',
      'category' => 'video', 'badge' => 'paid', 'badge_text' => 'Pro 會員', 'url' => 'https://openai.com/sora' ],
    [ 'icon' => '🌊', 'bg' => 'rgba(20,184,166,0.15)', 'name' => 'Seedance 2.0',
      'tag' => '字節跳動影片 AI', 'desc' => '字節跳動 2026 推出的多模態影片模型，可同時接受文字、圖、影、音最多 12 個素材輸入，支援音影同步生成，1080p 畫質與運動穩定度業界領先。',
      'category' => 'video', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://seed.bytedance.com/seedance2_0' ],
    [ 'icon' => '🎥', 'bg' => 'rgba(66,133,244,0.15)', 'name' => 'Veo 3',
      'tag' => 'Google 旗艦影片 AI', 'desc' => 'Google DeepMind 推出的影片生成模型，內建原生音效、對白與環境音同步生成，4K 畫質與物理真實感業界頂級。',
      'category' => 'video', 'badge' => 'paid', 'badge_text' => '付費', 'url' => 'https://deepmind.google/technologies/veo/' ],
    [ 'icon' => '🎬', 'bg' => 'rgba(236,72,153,0.15)', 'name' => 'Runway',
      'tag' => 'Gen-4 影片生成', 'desc' => '將靜態插畫動態化、文字生成短影片，業界元老級工具，Gen-4 大幅提升角色一致性，適合 MAD、宣傳影片。',
      'category' => 'video', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://runwayml.com/' ],
    [ 'icon' => '⚡', 'bg' => 'rgba(251,191,36,0.15)', 'name' => 'Kling AI',
      'tag' => '中國快手出品', 'desc' => '中國快手推出的 AI 影片生成，動作流暢度業界領先，2.0 版可生成最長 3 分鐘影片，動漫風格表現亮眼。',
      'category' => 'video', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://klingai.com/' ],
    [ 'icon' => '🐚', 'bg' => 'rgba(168,85,247,0.15)', 'name' => 'Hailuo AI',
      'tag' => 'MiniMax 海螺影片', 'desc' => '中國 MiniMax 推出的影片生成 AI，動作自然度極高，特別擅長人物表情、肢體動作，免費額度大方。',
      'category' => 'video', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://hailuoai.video/' ],
    [ 'icon' => '🎞️', 'bg' => 'rgba(124,58,237,0.15)', 'name' => 'Pika',
      'tag' => '社群型短片 AI', 'desc' => '使用者友好的 AI 影片工具，提供 Pikaffects 特效模板（爆炸、融化等），社群活躍上手簡單。',
      'category' => 'video', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://pika.art/' ],
    [ 'icon' => '🌌', 'bg' => 'rgba(59,130,246,0.15)', 'name' => 'Luma Dream Machine',
      'tag' => '3D 感影片生成', 'desc' => 'Luma 出品，擅長相機運鏡與 3D 空間感，Ray 2 模型畫面穩定度更高，適合電影感場景與動漫鏡頭分鏡。',
      'category' => 'video', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://lumalabs.ai/dream-machine' ],
    [ 'icon' => '📹', 'bg' => 'rgba(244,114,182,0.15)', 'name' => 'Vidu',
      'tag' => '生數科技影片 AI', 'desc' => '中國生數科技推出的影片生成模型，擅長角色一致性、多主體控制，動漫風格表現亮眼。',
      'category' => 'video', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://www.vidu.com/' ],
    [ 'icon' => '🎆', 'bg' => 'rgba(99,102,241,0.15)', 'name' => 'Higgsfield',
      'tag' => '電影感運鏡 AI', 'desc' => '專注於電影級運鏡控制的影片生成 AI，內建大量導演視角預設（推軌、Dolly Zoom 等）。',
      'category' => 'video', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://higgsfield.ai/' ],

    /* ═══════════ 🎵 AI 音樂（4 個） ═══════════ */
    [ 'icon' => '🎵', 'bg' => 'rgba(168,85,247,0.15)', 'name' => 'Suno',
      'tag' => 'AI 全曲生成王者', 'desc' => '輸入歌詞即可生成完整歌曲含人聲，支援 J-POP、動漫風格，創作自製 OP/ED 神器，v4 版音質大幅提升。',
      'category' => 'music', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://suno.com/' ],
    [ 'icon' => '🎶', 'bg' => 'rgba(99,102,241,0.15)', 'name' => 'Udio',
      'tag' => '高保真音樂生成', 'desc' => 'Suno 的強力對手，音質與人聲擬真度更高，可生成 32 秒到 15 分鐘各種長度的音樂。',
      'category' => 'music', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://www.udio.com/' ],
    [ 'icon' => '🎼', 'bg' => 'rgba(220,38,38,0.15)', 'name' => 'AIVA',
      'tag' => '純樂器作曲', 'desc' => '專注於純樂器作曲（無人聲），適合製作遊戲 BGM、動漫場景配樂，可下載 MIDI 編輯。',
      'category' => 'music', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://www.aiva.ai/' ],
    [ 'icon' => '🎺', 'bg' => 'rgba(34,197,94,0.15)', 'name' => 'Stable Audio',
      'tag' => '開源音效生成', 'desc' => 'Stability AI 推出，專注於音效與短曲段生成，適合遊戲音效、影片背景音樂。',
      'category' => 'music', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://stableaudio.com/' ],

    /* ═══════════ 👤 角色設計（3 個） ═══════════ */
    [ 'icon' => '👤', 'bg' => 'rgba(244,114,182,0.15)', 'name' => 'Character.AI',
      'tag' => '角色對話模擬', 'desc' => '與 AI 角色互動聊天，可作為角色性格設計、對白測試的輔助工具，海量社群角色庫。',
      'category' => 'character', 'badge' => 'free', 'badge_text' => '免費', 'url' => 'https://character.ai/' ],
    [ 'icon' => '👧', 'bg' => 'rgba(251,191,36,0.15)', 'name' => 'Waifu Labs',
      'tag' => '一鍵生成動漫角色', 'desc' => '專門生成動漫女角色的 AI 工具，4 步操作極簡單，適合快速產出角色設計概念稿。',
      'category' => 'character', 'badge' => 'free', 'badge_text' => '免費', 'url' => 'https://waifulabs.com/' ],
    [ 'icon' => '💖', 'bg' => 'rgba(236,72,153,0.15)', 'name' => 'Crypko',
      'tag' => '日本動漫角色 AI', 'desc' => '日本 Preferred Networks 推出的動漫角色生成 AI，全身像、可控姿勢、商用授權清晰。',
      'category' => 'character', 'badge' => 'paid', 'badge_text' => '付費', 'url' => 'https://crypko.ai/' ],

    /* ═══════════ 🧊 3D 建模（4 個） ═══════════ */
    [ 'icon' => '🧊', 'bg' => 'rgba(14,165,233,0.15)', 'name' => 'Meshy',
      'tag' => '文字 / 圖片 → 3D', 'desc' => '一張角色立繪輸入，幾秒生成完整 3D 模型含貼圖，適合 VRChat、遊戲開發前期原型。',
      'category' => 'model3d', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://www.meshy.ai/' ],
    [ 'icon' => '🗿', 'bg' => 'rgba(75,85,99,0.18)', 'name' => 'Tripo3D',
      'tag' => '高品質 3D 生成', 'desc' => '單張圖快速生成高品質 3D 模型，幾何細節與貼圖品質業界領先。',
      'category' => 'model3d', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://www.tripo3d.ai/' ],
    [ 'icon' => '🧞', 'bg' => 'rgba(168,85,247,0.15)', 'name' => 'Luma Genie',
      'tag' => 'Luma 3D 生成', 'desc' => 'Luma 推出的 3D 生成工具，可從文字或圖片產出可愛 3D 模型，社群分享活躍。',
      'category' => 'model3d', 'badge' => 'free', 'badge_text' => '免費', 'url' => 'https://lumalabs.ai/genie' ],
    [ 'icon' => '🏔️', 'bg' => 'rgba(34,197,94,0.15)', 'name' => 'Rodin',
      'tag' => '專業級 3D AI', 'desc' => 'Hyper3D 推出的高精度 3D 生成 AI，PBR 材質、四邊面拓樸結構，可直接用於遊戲與影視。',
      'category' => 'model3d', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://hyper3d.ai/' ],

    /* ═══════════ 🎞️ AI 動畫（5 個） ═══════════ */
    [ 'icon' => '🎞️', 'bg' => 'rgba(139,92,246,0.15)', 'name' => 'AnimateDiff',
      'tag' => 'SD 動畫擴充', 'desc' => 'Stable Diffusion 的動畫擴充模組，將靜態圖轉成短動畫，動漫圈最常用的免費動畫方案。',
      'category' => 'animation', 'badge' => 'free', 'badge_text' => '免費', 'url' => 'https://animatediff.github.io/' ],
    [ 'icon' => '🦸', 'bg' => 'rgba(220,38,38,0.15)', 'name' => 'Wonder Studio',
      'tag' => '影片角色置換', 'desc' => '將實拍影片中的人物自動置換成 CG 角色，含動作捕捉與燈光計算，影視級工具（已併入 Autodesk）。',
      'category' => 'animation', 'badge' => 'paid', 'badge_text' => '付費', 'url' => 'https://wonderdynamics.com/' ],
    [ 'icon' => '🕺', 'bg' => 'rgba(236,72,153,0.15)', 'name' => 'Viggle',
      'tag' => '角色動作生成', 'desc' => '上傳角色圖 + 動作參考影片，AI 自動生成角色跳同樣動作的影片，迷因與 MAD 創作神器。',
      'category' => 'animation', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://viggle.ai/' ],
    [ 'icon' => '✂️', 'bg' => 'rgba(0,209,178,0.15)', 'name' => 'CapCut AI',
      'tag' => '剪映 AI 動畫', 'desc' => 'TikTok 旗下剪映的 AI 功能整合，提供照片轉動畫、表情驅動、AI 美顏特效，社群創作者最常用。',
      'category' => 'animation', 'badge' => 'free', 'badge_text' => '免費', 'url' => 'https://www.capcut.com/' ],
    [ 'icon' => '🤸', 'bg' => 'rgba(251,146,60,0.15)', 'name' => 'Cascadeur',
      'tag' => 'AI 動作製作', 'desc' => 'AI 物理輔助的 3D 動作製作工具，自動補全動作中間幀、優化物理真實感，遊戲與動畫工作室愛用。',
      'category' => 'animation', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://cascadeur.com/' ],

    /* ═══════════ 💬 聊天助理（9 個） ═══════════ */
    [ 'icon' => '💬', 'bg' => 'rgba(16,185,129,0.15)', 'name' => 'ChatGPT',
      'tag' => 'OpenAI 全能對話', 'desc' => '使用者最廣的 AI 對話平台，內建繪圖、語音、Canvas、深度研究等全功能，AI 入門首選。',
      'category' => 'chatbot', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://chatgpt.com/' ],
    [ 'icon' => '🧠', 'bg' => 'rgba(217,119,6,0.15)', 'name' => 'Claude',
      'tag' => 'Anthropic AI', 'desc' => 'Anthropic 的旗艦對話 AI，超長上下文窗口、繁中能力業界第一，創意寫作與技術問答首選。',
      'category' => 'chatbot', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://claude.ai/' ],
    [ 'icon' => '✨', 'bg' => 'rgba(59,130,246,0.15)', 'name' => 'Gemini',
      'tag' => 'Google AI', 'desc' => 'Google 推出的多模態 AI，深度整合 Gmail、Docs、YouTube，免費版即可使用 Gemini 2.5。',
      'category' => 'chatbot', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://gemini.google.com/' ],
    [ 'icon' => '🌐', 'bg' => 'rgba(75,85,99,0.18)', 'name' => 'Grok',
      'tag' => 'xAI 即時資訊', 'desc' => 'xAI（馬斯克）推出的 AI，深度整合 X（Twitter）即時資訊，語氣較幽默不官腔。',
      'category' => 'chatbot', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://grok.com/' ],
    [ 'icon' => '🐋', 'bg' => 'rgba(14,165,233,0.15)', 'name' => 'DeepSeek',
      'tag' => '中國開源 AI', 'desc' => '中國 DeepSeek 推出的開源 AI，推理能力強、API 價格極低，引爆 2025 年初的 AI 平價革命。',
      'category' => 'chatbot', 'badge' => 'free', 'badge_text' => '免費', 'url' => 'https://chat.deepseek.com/' ],
    [ 'icon' => '🫘', 'bg' => 'rgba(251,146,60,0.15)', 'name' => '豆包',
      'tag' => '字節跳動 Doubao', 'desc' => '字節跳動推出的對話 AI，2025 年中國市占第一，多模態能力強（含 Seedance 影片生成），免費額度大方，繁中表現自然。',
      'category' => 'chatbot', 'badge' => 'free', 'badge_text' => '免費', 'url' => 'https://www.doubao.com/' ],
    [ 'icon' => '🌙', 'bg' => 'rgba(244,114,182,0.15)', 'name' => 'Kimi',
      'tag' => 'Moonshot 月之暗面', 'desc' => '中國月之暗面推出的 AI 助理，超長文檔閱讀能力強（200 萬字），檔案分析、學術研究首選。',
      'category' => 'chatbot', 'badge' => 'free', 'badge_text' => '免費', 'url' => 'https://kimi.moonshot.cn/' ],
    [ 'icon' => '☁️', 'bg' => 'rgba(99,102,241,0.15)', 'name' => '通義千問',
      'tag' => '阿里巴巴 AI', 'desc' => '阿里巴巴推出的對話 AI，Qwen 系列開源模型業界強勢，中英文表現均衡，可串接電商生態。',
      'category' => 'chatbot', 'badge' => 'free', 'badge_text' => '免費', 'url' => 'https://tongyi.aliyun.com/qianwen/' ],
    [ 'icon' => '🎓', 'bg' => 'rgba(220,38,38,0.15)', 'name' => 'Poe',
      'tag' => '多模型聚合', 'desc' => 'Quora 推出的 AI 聚合平台，一個帳號用 ChatGPT、Claude、Gemini、Grok 全部模型，方便比較。',
      'category' => 'chatbot', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://poe.com/' ],

    /* ═══════════ 🤖 AI Agent（6 個） ═══════════ */
    [ 'icon' => '⚡', 'bg' => 'rgba(251,146,60,0.15)', 'name' => 'Genspark',
      'tag' => '全能 AI 工作空間', 'desc' => '前百度高管創立的 all-in-one AI 平台，集合 Super Agent、簡報、文件、影片、程式生成於一身，2025 年最熱新創之一。',
      'category' => 'agent', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://www.genspark.ai/' ],
    [ 'icon' => '🧑‍💻', 'bg' => 'rgba(220,38,38,0.15)', 'name' => 'Devin',
      'tag' => '自動軟體工程師', 'desc' => 'Cognition 推出的首位 AI 軟體工程師，能自主規劃、寫程式、除錯、部署，整套工作流程都能執行。',
      'category' => 'agent', 'badge' => 'paid', 'badge_text' => '付費', 'url' => 'https://www.cognition.ai/devin' ],
    [ 'icon' => '🎯', 'bg' => 'rgba(168,85,247,0.15)', 'name' => 'Manus',
      'tag' => '通用型 AI Agent', 'desc' => '中國團隊推出的通用 AI 代理，可自主完成複雜任務、操作瀏覽器，被譽為「最接近 AGI 的 Agent」。',
      'category' => 'agent', 'badge' => 'paid', 'badge_text' => '邀請制', 'url' => 'https://manus.im/' ],
    [ 'icon' => '🖱️', 'bg' => 'rgba(34,197,94,0.15)', 'name' => 'OpenAI Operator',
      'tag' => '瀏覽器操作 Agent', 'desc' => 'OpenAI 推出的瀏覽器自動化 Agent，可代填表單、訂機票、購物，內建於 ChatGPT Pro。',
      'category' => 'agent', 'badge' => 'paid', 'badge_text' => 'Pro 會員', 'url' => 'https://openai.com/index/introducing-operator/' ],
    [ 'icon' => '🤖', 'bg' => 'rgba(99,102,241,0.15)', 'name' => 'AutoGPT',
      'tag' => '開源自主 Agent', 'desc' => '開源 AI Agent 框架元老，可自主分解任務、執行多步驟工作，開發者社群活躍。',
      'category' => 'agent', 'badge' => 'free', 'badge_text' => '免費', 'url' => 'https://agpt.co/' ],
    [ 'icon' => '🔮', 'bg' => 'rgba(139,92,246,0.15)', 'name' => 'Claude Computer Use',
      'tag' => 'Claude 電腦控制', 'desc' => 'Anthropic 推出的電腦操作 Agent，Claude 可看螢幕、移動滑鼠、輸入文字，完整接管電腦執行任務。',
      'category' => 'agent', 'badge' => 'paid', 'badge_text' => '付費', 'url' => 'https://www.anthropic.com/news/3-5-models-and-computer-use' ],

    /* ═══════════ 💻 程式輔助（7 個） ═══════════ */
    [ 'icon' => '⌨️', 'bg' => 'rgba(59,130,246,0.15)', 'name' => 'Cursor',
      'tag' => 'AI 第一編輯器', 'desc' => '基於 VS Code 改造的 AI 編輯器，內建 Claude/GPT 深度整合，2026 年開發者社群最熱門 IDE。',
      'category' => 'coding', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://cursor.com/' ],
    [ 'icon' => '🌪️', 'bg' => 'rgba(20,184,166,0.15)', 'name' => 'Windsurf',
      'tag' => 'Codeium AI IDE', 'desc' => 'Codeium 推出的 AI 編輯器，Cascade 模式可自主完成跨檔修改，與 Cursor 形成兩強競爭。',
      'category' => 'coding', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://windsurf.com/' ],
    [ 'icon' => '🐙', 'bg' => 'rgba(75,85,99,0.18)', 'name' => 'GitHub Copilot',
      'tag' => 'GitHub 官方 AI', 'desc' => 'GitHub 出品的 AI 程式助理，深度整合 VS Code、JetBrains，企業開發者愛用。',
      'category' => 'coding', 'badge' => 'paid', 'badge_text' => '付費', 'url' => 'https://github.com/features/copilot' ],
    [ 'icon' => '💻', 'bg' => 'rgba(217,119,6,0.15)', 'name' => 'Claude Code',
      'tag' => '終端機 AI', 'desc' => 'Anthropic 推出的命令列 AI 助手，能在終端中讀檔、寫程式、執行任務，可委派完整工作。',
      'category' => 'coding', 'badge' => 'paid', 'badge_text' => '訂閱', 'url' => 'https://www.anthropic.com/claude-code' ],
    [ 'icon' => '🚀', 'bg' => 'rgba(124,58,237,0.15)', 'name' => 'v0 by Vercel',
      'tag' => 'AI 生成 UI', 'desc' => '輸入文字描述，直接生成可用的 React + Tailwind 元件，前端開發起手式神器。',
      'category' => 'coding', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://v0.dev/' ],
    [ 'icon' => '💗', 'bg' => 'rgba(236,72,153,0.15)', 'name' => 'Lovable',
      'tag' => 'AI 全端開發', 'desc' => '用對話就能生成完整可部署的 web app，非工程師也能做出 SaaS 產品，2025 年最爆紅 vibe coding 工具。',
      'category' => 'coding', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://lovable.dev/' ],
    [ 'icon' => '⚡', 'bg' => 'rgba(251,191,36,0.15)', 'name' => 'Bolt.new',
      'tag' => 'StackBlitz AI 開發', 'desc' => '瀏覽器內 AI 全端開發環境，從文字描述到可部署 app 一站式，前端 + 後端 + 資料庫全包。',
      'category' => 'coding', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://bolt.new/' ],

    /* ═══════════ 🔎 AI 搜尋（4 個） ═══════════ */
    [ 'icon' => '🔍', 'bg' => 'rgba(14,165,233,0.15)', 'name' => 'Perplexity',
      'tag' => 'AI 搜尋引擎之王', 'desc' => '取代 Google 的 AI 搜尋，提供來源引用、即時資訊、深度研究模式（Deep Research），研究查資料神器。',
      'category' => 'search', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://www.perplexity.ai/' ],
    [ 'icon' => '🔎', 'bg' => 'rgba(34,197,94,0.15)', 'name' => 'Phind',
      'tag' => '工程師專用搜尋', 'desc' => '工程師專用 AI 搜尋，給程式碼答案附帶來源、可執行、可對話追問，技術問題首選。',
      'category' => 'search', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://www.phind.com/' ],
    [ 'icon' => '🎯', 'bg' => 'rgba(168,85,247,0.15)', 'name' => 'Felo',
      'tag' => '日系 AI 搜尋', 'desc' => '日本團隊推出的 AI 搜尋，日文資料覆蓋業界最強，可生成簡報、心智圖，日本動漫情報研究首選。',
      'category' => 'search', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://felo.ai/' ],
    [ 'icon' => '💭', 'bg' => 'rgba(244,63,94,0.15)', 'name' => 'You.com',
      'tag' => 'AI 搜尋助理', 'desc' => '結合對話與搜尋的 AI 平台，內建多種研究模式與引用，可自訂搜尋來源範圍。',
      'category' => 'search', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://you.com/' ],

    /* ═══════════ 📊 生產力（5 個） ═══════════ */
    [ 'icon' => '📝', 'bg' => 'rgba(75,85,99,0.18)', 'name' => 'Notion AI',
      'tag' => '筆記內整合 AI', 'desc' => 'Notion 內建 AI，可摘要、續寫、翻譯、生成大綱，與筆記系統無縫整合。',
      'category' => 'productivity', 'badge' => 'paid', 'badge_text' => '付費', 'url' => 'https://www.notion.so/product/ai' ],
    [ 'icon' => '📚', 'bg' => 'rgba(66,133,244,0.15)', 'name' => 'NotebookLM',
      'tag' => 'Google AI 筆記本', 'desc' => 'Google 推出，可上傳資料生成摘要、Q&A、心智圖，獨家「AI Podcast」功能可將文件轉成雙人對話音頻。',
      'category' => 'productivity', 'badge' => 'free', 'badge_text' => '免費', 'url' => 'https://notebooklm.google.com/' ],
    [ 'icon' => '🧠', 'bg' => 'rgba(99,102,241,0.15)', 'name' => 'Mem',
      'tag' => 'AI 第二大腦', 'desc' => '基於 AI 的筆記工具，自動連結相關筆記、生成知識圖譜，適合資訊量大的研究者。',
      'category' => 'productivity', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://get.mem.ai/' ],
    [ 'icon' => '🎧', 'bg' => 'rgba(244,114,182,0.15)', 'name' => 'Granola',
      'tag' => 'AI 會議筆記', 'desc' => '自動聽會議、生成重點筆記，並標記行動項目，遠距工作者神器。',
      'category' => 'productivity', 'badge' => 'paid', 'badge_text' => '付費', 'url' => 'https://www.granola.ai/' ],
    [ 'icon' => '✅', 'bg' => 'rgba(34,197,94,0.15)', 'name' => 'Reclaim.ai',
      'tag' => 'AI 行事曆', 'desc' => 'AI 自動排程行事曆，根據優先級與工作習慣自動安排任務時段，提升專注時間。',
      'category' => 'productivity', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://reclaim.ai/' ],

    /* ═══════════ 🌏 AI 翻譯（3 個） ═══════════ */
    [ 'icon' => '🌏', 'bg' => 'rgba(14,165,233,0.15)', 'name' => 'DeepL',
      'tag' => '翻譯品質之王', 'desc' => '業界公認翻譯品質最佳，日中翻譯尤其精準，動漫情報翻譯首選工具。',
      'category' => 'translate', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://www.deepl.com/' ],
    [ 'icon' => '🪄', 'bg' => 'rgba(139,92,246,0.15)', 'name' => '沉浸式翻譯',
      'tag' => '網頁雙語翻譯', 'desc' => '免費瀏覽器擴充套件，網頁雙語對照翻譯，可串接 GPT / Claude / DeepL，看日英網頁神器。',
      'category' => 'translate', 'badge' => 'free', 'badge_text' => '免費', 'url' => 'https://immersivetranslate.com/' ],
    [ 'icon' => '📖', 'bg' => 'rgba(251,146,60,0.15)', 'name' => 'Mantra',
      'tag' => '漫畫翻譯 AI', 'desc' => '專為漫畫設計的 AI 翻譯工具，可自動辨識對白氣泡、保留排版、翻譯後直接嵌回，漢化組必備。',
      'category' => 'translate', 'badge' => 'paid', 'badge_text' => '付費', 'url' => 'https://mantra.co.jp/' ],

    /* ═══════════ 🖼️ 設計輔助（4 個） ═══════════ */
    [ 'icon' => '🎨', 'bg' => 'rgba(168,85,247,0.15)', 'name' => 'Figma AI',
      'tag' => '設計師標配', 'desc' => 'Figma 內建 AI，可從文字生成設計稿、自動排版、智能填色，設計師工作流程必備。',
      'category' => 'design', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://www.figma.com/ai/' ],
    [ 'icon' => '🖼️', 'bg' => 'rgba(34,197,94,0.15)', 'name' => 'Canva AI',
      'tag' => '一般人也能設計', 'desc' => 'Canva 整合的 AI 套件（Magic Design / Magic Write / Magic Edit），新手也能做出專業設計。',
      'category' => 'design', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://www.canva.com/ai/' ],
    [ 'icon' => '🔥', 'bg' => 'rgba(220,38,38,0.15)', 'name' => 'Adobe Firefly',
      'tag' => 'Adobe 生態 AI', 'desc' => 'Adobe 推出的 AI 創意工具，深度整合 Photoshop / Illustrator / Premiere，商業授權清晰。',
      'category' => 'design', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://www.adobe.com/products/firefly.html' ],
    [ 'icon' => '✨', 'bg' => 'rgba(124,58,237,0.15)', 'name' => 'Galileo AI',
      'tag' => 'AI UI 設計', 'desc' => '輸入文字描述生成完整 UI 設計稿，可匯出至 Figma 編輯，UI/UX 設計師加速器。',
      'category' => 'design', 'badge' => 'freemium', 'badge_text' => '免費試用', 'url' => 'https://www.usegalileo.ai/' ],

    /* ═══════════ 🎓 學習教育（3 個） ═══════════ */
    [ 'icon' => '🎓', 'bg' => 'rgba(34,197,94,0.15)', 'name' => 'Khanmigo',
      'tag' => 'Khan Academy AI', 'desc' => '可汗學院推出的 AI 家教，因材施教引導學生解題，K-12 數理學習神器。',
      'category' => 'edu', 'badge' => 'paid', 'badge_text' => '付費', 'url' => 'https://www.khanmigo.ai/' ],
    [ 'icon' => '🗣️', 'bg' => 'rgba(251,191,36,0.15)', 'name' => 'Speak',
      'tag' => 'AI 口語教練', 'desc' => '用 AI 跟你練英文口說，OpenAI 投資，發音矯正即時回饋，學語言新方式。',
      'category' => 'edu', 'badge' => 'paid', 'badge_text' => '付費', 'url' => 'https://www.speak.com/' ],
    [ 'icon' => '🌸', 'bg' => 'rgba(244,114,182,0.15)', 'name' => 'Duolingo Max',
      'tag' => 'AI 語言學習', 'desc' => 'Duolingo 推出的 GPT-4 加值版本，AI 對話練習、解題詳解，學日文、英文更高效。',
      'category' => 'edu', 'badge' => 'paid', 'badge_text' => '付費', 'url' => 'https://www.duolingo.com/super' ],

];


/* ── 分類定義 ── */
$ai_categories = [
    'all'         => '全部',
    'agent'       => '🤖 AI Agent',
    'drawing'     => '🎨 AI 繪圖',
    'writing'     => '📝 AI 寫作',
    'voice'       => '🎙️ AI 語音',
    'video'       => '🎬 AI 影片',
    'music'       => '🎵 AI 音樂',
    'character'   => '👤 角色設計',
    'model3d'     => '🧊 3D 建模',
    'animation'   => '🎞️ AI 動畫',
    'chatbot'     => '💬 聊天助理',
    'coding'      => '💻 程式輔助',
    'search'      => '🔎 AI 搜尋',
    'productivity'=> '📊 生產力',
    'translate'   => '🌏 翻譯',
    'design'      => '🖼️ 設計',
    'edu'         => '🎓 教育',
];

/* ============================================================
 * 🔍 SEO：ItemList JSON-LD Schema（v2.3.0 新增）
 * ------------------------------------------------------------
 * 將 75+ 工具輸出為結構化資料，協助 Google / AI 搜尋
 * 理解本頁為「AI 工具清單」，提升 Rich Result 機會。
 * 與 Rank Math 的 BreadcrumbList 並存，不衝突。
 * ============================================================ */
$schema_items = [];
$position     = 1;
foreach ( $ai_tools as $tool ) {
    $schema_items[] = [
        '@type'    => 'ListItem',
        'position' => $position++,
        'item'     => [
            '@type'               => 'SoftwareApplication',
            'name'                => wp_strip_all_tags( $tool['name'] ),
            'description'         => wp_strip_all_tags( $tool['desc'] ),
            'url'                 => esc_url_raw( $tool['url'] ),
            'applicationCategory' => 'MultimediaApplication',
        ],
    ];
}

$itemlist_schema = [
    '@context'        => 'https://schema.org',
    '@type'           => 'ItemList',
    'name'            => 'AI 工具推薦懶人包 — ' . count( $ai_tools ) . '+ 款 AI 繪圖、影片、寫作工具總整理',
    'description'     => '微笑動漫整理 ' . count( $ai_tools ) . '+ 款熱門 AI 工具，涵蓋 AI 繪圖、AI 影片、AI 寫作、AI 音樂、AI Agent 等領域。',
    'numberOfItems'   => count( $ai_tools ),
    'itemListOrder'   => 'https://schema.org/ItemListUnordered',
    'itemListElement' => $schema_items,
];

/* ============================================================
 * 📰 抓取 ai-tools 內容頻道的最新文章
 * ============================================================ */
$ai_news_query = new WP_Query( [
    'post_type'           => 'post',
    'posts_per_page'      => 8,
    'post_status'         => 'publish',
    'ignore_sticky_posts' => true,
    'tax_query'           => [
        [
            'taxonomy' => 'channel',           // ⚠️ 如你的 taxonomy 不是 channel，請改這裡
            'field'    => 'slug',
            'terms'    => 'ai-tools',
        ],
    ],
] );

/* 跑馬燈用：抓最新 5 篇標題 */
$ticker_query = new WP_Query( [
    'post_type'           => 'post',
    'posts_per_page'      => 5,
    'post_status'         => 'publish',
    'ignore_sticky_posts' => true,
    'tax_query'           => [
        [
            'taxonomy' => 'channel',
            'field'    => 'slug',
            'terms'    => 'ai-tools',
        ],
    ],
] );
?>

<!-- ===== SEO：ItemList 結構化資料（v2.3.0）===== -->
<script type="application/ld+json">
<?php echo wp_json_encode( $itemlist_schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>
</script>

<!-- 載入 AI 工具頁專用樣式 -->
<link rel="stylesheet" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/css/ai-tools.css' ); ?>?v=2.3.0" />

<!-- ===== HERO（緊湊版）===== -->
<div class="page-hero ai-hero">
  <div class="container">
    <div class="page-badge ai-badge">
      <i class="fa-solid fa-robot"></i> AI 工具情報站
    </div>
    <h1 class="page-title">AI × 動漫創作前線</h1>
    <p class="page-subtitle">從 Agent 到繪圖，從寫作到音樂 —— 給每位想用 AI 創作的動漫迷，工具、教學、應用情報一次到位</p>

    <div class="ai-hero-meta">
      <span class="ai-hero-meta-item">🛠️ <strong><?php echo count( $ai_tools ); ?>+</strong> 精選工具</span>
      <span class="ai-hero-meta-divider">·</span>
      <span class="ai-hero-meta-item">📂 <strong><?php echo count( $ai_categories ) - 1; ?></strong> 領域分類</span>
      <span class="ai-hero-meta-divider">·</span>
      <span class="ai-hero-meta-item">📰 <strong><?php echo (int) $ai_news_query->found_posts; ?></strong> 相關情報</span>
    </div>
  </div>
</div>

<!-- ===== 📢 最新情報跑馬燈 ===== -->
<?php if ( $ticker_query->have_posts() ) : ?>
<div class="ai-ticker">
  <div class="container">
    <div class="ai-ticker-inner">
      <div class="ai-ticker-label">
        <i class="fa-solid fa-bullhorn"></i> 最新情報
      </div>
      <div class="ai-ticker-track">
        <div class="ai-ticker-content">
          <?php
          // 第一輪：原始輸出
          while ( $ticker_query->have_posts() ) : $ticker_query->the_post(); ?>
            <a href="<?php the_permalink(); ?>" class="ai-ticker-item">
              <span class="ai-ticker-dot">●</span>
              <span class="ai-ticker-title"><?php echo esc_html( get_the_title() ); ?></span>
              <span class="ai-ticker-date"><?php echo esc_html( get_the_date( 'm/d' ) ); ?></span>
            </a>
          <?php endwhile; ?>
          <?php
          // 第二輪：重置指標後再輸出一次，做無縫接續（aria-hidden 避免重複朗讀）
          $ticker_query->rewind_posts();
          while ( $ticker_query->have_posts() ) : $ticker_query->the_post(); ?>
            <a href="<?php the_permalink(); ?>" class="ai-ticker-item" aria-hidden="true" tabindex="-1">
              <span class="ai-ticker-dot">●</span>
              <span class="ai-ticker-title"><?php echo esc_html( get_the_title() ); ?></span>
              <span class="ai-ticker-date"><?php echo esc_html( get_the_date( 'm/d' ) ); ?></span>
            </a>
          <?php endwhile; ?>
          <?php wp_reset_postdata(); ?>
        </div>
      </div>
      <a href="<?php echo esc_url( home_url( '/news/ai-tools/' ) ); ?>" class="ai-ticker-more">
        全部 <i class="fa-solid fa-arrow-right"></i>
      </a>
    </div>
  </div>
</div>
<?php endif; ?>


<main class="container" style="padding: 0 0 64px;">

  <!-- ===== 📰 AI 工具相關情報（移至工具牆上方）===== -->
  <?php if ( $ai_news_query->have_posts() ) : ?>
  <section class="ai-news-section">
    <div class="ai-news-header">
      <div>
        <h2 class="ai-news-h2"><i class="fa-solid fa-newspaper"></i> AI 工具相關情報</h2>
        <p class="ai-news-sub">最新的 AI 工具評測、教學與業界動態</p>
      </div>
      <a href="<?php echo esc_url( home_url( '/news/ai-tools/' ) ); ?>" class="ai-news-more">
        查看更多 <i class="fa-solid fa-arrow-right"></i>
      </a>
    </div>

    <div class="ai-news-grid">
      <?php while ( $ai_news_query->have_posts() ) : $ai_news_query->the_post();
          $thumb_url = '';
          if ( has_post_thumbnail() ) {
              $thumb_url = get_the_post_thumbnail_url( get_the_ID(), 'medium' );
          } else {
              preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', get_the_content(), $m );
              if ( ! empty( $m[1] ) ) $thumb_url = $m[1];
          }
      ?>
        <a href="<?php the_permalink(); ?>" class="ai-news-card glass">
          <div class="ai-news-card-img">
            <?php if ( $thumb_url ) : ?>
              <img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" loading="lazy" />
            <?php else : ?>
              <span class="ai-news-card-placeholder">🤖</span>
            <?php endif; ?>
          </div>
          <div class="ai-news-card-body">
            <div class="ai-news-card-title"><?php the_title(); ?></div>
            <div class="ai-news-card-meta">
              <i class="fa-regular fa-clock"></i> <?php echo get_the_date( 'Y-m-d' ); ?>
            </div>
          </div>
        </a>
      <?php endwhile; wp_reset_postdata(); ?>
    </div>
  </section>
  <?php endif; ?>


  <!-- ===== 工具完整資料庫 ===== -->
  <section class="ai-tools-section">
    <div class="ai-tools-header">
      <h2 class="ai-tools-h2">完整 AI 工具資料庫</h2>
      <p class="ai-tools-sub">點按分類篩選 · 共 <?php echo count( $ai_tools ); ?> 個工具</p>
    </div>

    <!-- Filter -->
    <div class="tool-filter" id="ai-tool-filter">
      <?php foreach ( $ai_categories as $slug => $label ) :
          $active = ( $slug === 'all' ) ? ' active' : '';
      ?>
        <button type="button"
                class="tool-filter-btn<?php echo $active; ?>"
                data-category="<?php echo esc_attr( $slug ); ?>">
          <?php echo esc_html( $label ); ?>
        </button>
      <?php endforeach; ?>
    </div>

    <!-- Tools Grid -->
    <div class="tools-grid" id="ai-tools-grid">
      <?php foreach ( $ai_tools as $tool ) : ?>
        <article class="tool-card glass"
                 data-category="<?php echo esc_attr( $tool['category'] ); ?>">

          <div class="tool-card-header">
            <div class="tool-icon" style="background: <?php echo esc_attr( $tool['bg'] ); ?>;">
              <?php echo esc_html( $tool['icon'] ); ?>
            </div>
            <div class="tool-meta">
              <div class="tool-name"><?php echo esc_html( $tool['name'] ); ?></div>
              <div class="tool-tag"><?php echo esc_html( $tool['tag'] ); ?></div>
            </div>
          </div>

          <div class="tool-desc"><?php echo esc_html( $tool['desc'] ); ?></div>

          <div class="tool-footer">
            <span class="tool-badge <?php echo esc_attr( $tool['badge'] ); ?>">
              <?php echo esc_html( $tool['badge_text'] ); ?>
            </span>
            <a href="<?php echo esc_url( $tool['url'] ); ?>"
               target="_blank"
               rel="noopener nofollow"
               class="tool-link">
              前往 <i class="fa-solid fa-arrow-up-right-from-square"></i>
            </a>
          </div>

        </article>
      <?php endforeach; ?>
    </div>

    <!-- Empty State -->
    <div class="ai-empty glass" id="ai-tools-empty" hidden>
      <i class="fa-solid fa-magnifying-glass"></i>
      <p>這個分類目前沒有工具，歡迎推薦給我們！</p>
    </div>
  </section>


  <!-- ===== Disclaimer ===== -->
  <div class="ai-disclaimer glass">
    <i class="fa-solid fa-circle-info"></i>
    <div>
      <strong>免責聲明：</strong>本頁所列工具皆為第三方服務，使用前請詳閱各服務條款。
      AI 生成內容請尊重原作著作權，避免商業濫用。
      工具資訊截至 <?php echo esc_html( date_i18n( 'Y-m-d' ) ); ?>，最新狀況以官方為準。
    </div>
  </div>

</main>

<script>
(function () {
  'use strict';

  /* ── 分類 Filter ── */
  const filterBar = document.getElementById('ai-tool-filter');
  const grid      = document.getElementById('ai-tools-grid');
  const empty     = document.getElementById('ai-tools-empty');

  if (!filterBar || !grid) return;

  filterBar.addEventListener('click', function (e) {
    const btn = e.target.closest('.tool-filter-btn');
    if (!btn) return;

    filterBar.querySelectorAll('.tool-filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    const cat = btn.dataset.category;
    const cards = grid.querySelectorAll('.tool-card');
    let visibleCount = 0;

    cards.forEach(card => {
      const show = (cat === 'all' || card.dataset.category === cat);
      card.style.display = show ? '' : 'none';
      if (show) visibleCount++;
    });

    if (empty) empty.hidden = (visibleCount > 0);
  });
})();
</script>

<?php get_footer(); ?>
