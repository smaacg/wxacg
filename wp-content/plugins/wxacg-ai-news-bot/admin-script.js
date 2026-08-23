/* ==========================================
 * 微笑動漫 AI 新聞管理 AJAX 處理代碼
 * ========================================== */

jQuery(document).ready(function($) {
    let pollingTimer = null;
    let currentTaskID = "";
    let terminalScreen = $("#wxacg_terminal_screen");
    let pollCount = 0;           // 本次任務已累積的輪詢次數
    const MAX_POLL_COUNT = 300;  // 最多輪詢 300 次（約 10 分鐘），足以涵蓋 API 冷卻重試與多階段備援的完整執行時間

    function getTerminalTime() {
        let d = new Date();
        let h = String(d.getHours()).padStart(2, "0");
        let m = String(d.getMinutes()).padStart(2, "0");
        let s = String(d.getSeconds()).padStart(2, "0");
        return "[" + h + ":" + m + ":" + s + "]";
    }

    function logToTerminal(msg, type = "normal") {
        var timeStr = "<span class=\"term-time\">" + getTerminalTime() + "</span> ";
        var cls = "";
        if (type === "error" || msg.indexOf("❌") !== -1 || msg.indexOf("失敗") !== -1 || msg.indexOf("錯誤") !== -1) {
            cls = "term-line-err";
        } else if (type === "success" || msg.indexOf("🟢") !== -1 || msg.indexOf("成功") !== -1 || msg.indexOf("完成") !== -1) {
            cls = "term-line-ok";
        } else if (type === "warn" || msg.indexOf("⚠️") !== -1 || msg.indexOf("🛑") !== -1) {
            // 中止相關訊息不帶 ⚠️ 也需標為警示色，故一併認 type 與 🛑
            cls = "term-line-warn";
        }

        var lineDiv = $("<div class=\"term-line " + cls + "\">" + timeStr + "&gt; " + $("<div>").text(msg).html() + "</div>");
        terminalScreen.append(lineDiv);
        terminalScreen.scrollTop(terminalScreen[0].scrollHeight);
    }

    // 1. 解開上鎖的端點設定及改取新防護密碼
    $("#wxacg_btn_unlock").on("click", function() {
        var inputVal = $("#wxacg_unlock_input").val();
        var correctVal = $("#wxacg_ai_news_unlock_password").val() || "123456789";

        if (inputVal === correctVal) {
            $("#wxacg_lock_guard_area").slideUp();
            $("#wxacg_cloud_secret_fields").slideDown();
            logToTerminal("資安解鎖檢驗通過！您現在可修改端點 URL 並得自訂專門的解鎖新口令。", "success");
        } else {
            alert("口令驗證有誤！權限不予通行。");
            $("#wxacg_unlock_input").val("").focus();
        }
    });

    // 1-B. 解除【全站共用金鑰池】的資安鎖
    // 與上方雲端端點的解鎖不同：金鑰池密碼以雜湊存在資料庫，前端拿不到明文，
    // 因此改送 AJAX 交由伺服器端驗證。這道鎖只負責畫面上的開合，
    // 真正的防線在儲存時的伺服器端檢查——直接送表單一樣過不了。
    $("#wxacg_btn_key_unlock").on("click", function() {
        var btn = $(this);
        var password = $("#wxacg_key_unlock_input").val();

        if (!password) {
            alert("請先輸入 Key 池管理密碼。");
            $("#wxacg_key_unlock_input").focus();
            return;
        }

        btn.prop("disabled", true).text("驗證中...");

        $.post(wxacgAIParams.ajaxurl, {
            action: "wxacg_verify_key_password",
            nonce: wxacgAIParams.nonce,
            password: password
        }, function(res) {
            btn.prop("disabled", false).text("🔓 解除金鑰鎖");

            if (res.success) {
                // 把剛才輸入的密碼帶進隱藏欄位，隨表單一起送出，免得同一組密碼要打兩次
                $("#wxacg_ai_news_key_password").val(password);
                $("#wxacg_key_unlock_input").val("");
                $("#wxacg_key_lock_guard").slideUp();
                $("#wxacg_key_pool_fields").slideDown();
                logToTerminal("金鑰池資安鎖已解除，現在可以修改全站共用 API Key。", "success");
            } else {
                alert("解鎖失敗：" + ((res.data && res.data.message) ? res.data.message : "管理密碼錯誤"));
                $("#wxacg_key_unlock_input").val("").focus();
            }
        }).fail(function() {
            btn.prop("disabled", false).text("🔓 解除金鑰鎖");
            alert("發生網路錯誤，請重試！");
        });
    });

    // 密碼欄位按 Enter 等同按下解鎖，避免誤觸整張表單的送出
    $("#wxacg_key_unlock_input").on("keydown", function(e) {
        if (e.key === "Enter") {
            e.preventDefault();
            $("#wxacg_btn_key_unlock").click();
        }
    });

    // 2. 清除終端日誌畫面
    $("#wxacg_btn_clear_log").on("click", function(e) {
        e.preventDefault();
        terminalScreen.empty().append("<div class=\"term-line\"><span class=\"term-time\">" + getTerminalTime() + "</span> &gt; 日誌紀錄被刷清。等待獲取新消息。</div>");
    });

    // 3. 生成／中止（同一顆按鈕依狀態切換）
    // 金鑰池變大後，遇到模型端持續壅塞時整池輪完可能耗時數十分鐘，
    // 故任務進行中時按鈕轉為中止鍵，讓操作者能隨時喊停。
    var isGenerating = false;      // 目前是否有任務進行中
    var cancelRequested = false;   // 是否已按下中止（含 task_id 尚未回來就先按的情況）

    var BTN_START = "開始生成報導";
    var BTN_STOP  = "⏹️ 中止生成";

    function setGeneratingState(on) {
        isGenerating = on;
        var btn = $("#wxacg_btn_generate");
        if (on) {
            btn.prop("disabled", false)
               .text(BTN_STOP)
               .addClass("wxacg-btn-stop")
               .removeClass("button-primary");
        } else {
            btn.prop("disabled", false)
               .text(BTN_START)
               .removeClass("wxacg-btn-stop")
               .addClass("button-primary");
        }
    }

    function stopPolling() {
        if (pollingTimer) {
            clearInterval(pollingTimer);
            pollingTimer = null;
        }
    }

    // 送出中止指令。task_id 尚未回來時先記旗標，等領到號碼再補送。
    function cancelGeneration() {
        var btn = $("#wxacg_btn_generate");
        cancelRequested = true;

        if (!currentTaskID) {
            logToTerminal("已標記中止，正等待雲端回傳任務編碼後立即送出……", "warn");
            btn.prop("disabled", true).text("中止中...");
            return;
        }

        btn.prop("disabled", true).text("中止中...");
        logToTerminal("正在送出中止指令……");

        $.post(wxacgAIParams.ajaxurl, {
            action: "wxacg_cancel_ai_news",
            nonce: wxacgAIParams.nonce,
            task_id: currentTaskID
        }, function(res) {
            if (res.success) {
                logToTerminal("🛑 " + (res.data.message || "已送出中止指令。") +
                              " 雲端會在目前這一步結束後停止，稍候片刻。");
            } else {
                logToTerminal("❌ 中止失敗：" + ((res.data && res.data.message) ? res.data.message : "未知原因"), "error");
                // 中止沒送成功就恢復成中止鍵，讓使用者可以再試一次
                setGeneratingState(true);
                cancelRequested = false;
            }
        }).fail(function() {
            logToTerminal("❌ 送出中止指令時發生網路錯誤，請重試。", "error");
            setGeneratingState(true);
            cancelRequested = false;
        });
    }

    $("#wxacg_btn_generate").on("click", function(e) {
        e.preventDefault();

        if (isGenerating) {
            cancelGeneration();
            return;
        }

        var targetUrl = $("#wxacg_target_url").val().trim();
        var customGlossary = $("#wxacg_custom_glossary").val().trim();
        var targetCategory = $("#wxacg_target_category").val();
        var targetChannel = $("#wxacg_target_channel").val();
        var style = $("#wxacg_ai_style").val() || "comprehensive";
        var styleText = $("#wxacg_ai_style option:selected").text() || "B. 新作完整情報型";

        if (!targetUrl) {
            alert("提醒：您必須貼上您要轉化的目標海外報導文章網址。");
            $("#wxacg_target_url").focus();
            return;
        }

        logToTerminal("收到下單！組成 JSON 架構並投標發往 GCP 雲端大本營...");
        logToTerminal("指定去向 -> 分類號: " + targetCategory + " | 頻道: " + targetChannel + " | 模板: [" + styleText + "]");
        
        var btn = $("#wxacg_btn_generate");
        // 派工請求送出期間先鎖住按鈕，避免連點造成重複下單；
        // 領到 task_id 後才切換成可按的中止鍵。
        btn.prop("disabled", true).text("派工中...");
        currentTaskID = "";
        cancelRequested = false;
        stopPolling();

        $.ajax({
            url: wxacgAIParams.ajaxurl,
            type: "POST",
            data: {
                action: "wxacg_trigger_ai_news",
                nonce: wxacgAIParams.nonce,
                target_url: targetUrl,
                style: style,
                custom_glossary: customGlossary,
                target_category: targetCategory,
                target_channel: targetChannel
            },
            success: function(res) {
                if (res.success) {
                    currentTaskID = res.data.task_id;
                    logToTerminal("遠端機房成功領件！ task_id : " + currentTaskID, "success");

                    // 在領到號碼前就按過中止：現在補送指令，不讓任務繼續跑下去
                    if (cancelRequested) {
                        logToTerminal("偵測到先前已按下中止，立即補送中止指令……", "warn");
                        cancelRequested = false;
                        setGeneratingState(true);
                        cancelGeneration();
                        return;
                    }

                    logToTerminal("正展開 Live 監測，每 2 秒一次接收雲端實景運營工務日誌...");
                    setGeneratingState(true);

                    pollCount = 0;  // 啟動新輪詢前重置計數器
                    pollingTimer = setInterval(pollTaskStatus, 2000);
                } else {
                    logToTerminal("❌ 無法通報任務： " + res.data.message, "error");
                    setGeneratingState(false);
                }
            },
            error: function(xhr, status, error) {
                logToTerminal("❌ 本地通訊斷裂受難： " + error, "error");
                setGeneratingState(false);
            }
        });
    });

    // 4. 定期輪詢獲取遠端伺服工作狀況
    function pollTaskStatus() {
        if (!currentTaskID) return;

        // 超過最大輪詢次數，自動放棄，防止 Cloud Run 掛掉或任務卡死時無限佔用資源
        pollCount++;
        if (pollCount >= MAX_POLL_COUNT) {
            stopPolling();
            logToTerminal("⚠️ 輪詢已達上限（" + MAX_POLL_COUNT + " 次 / 約 " +
                          Math.round(MAX_POLL_COUNT * 2 / 60) + " 分鐘），雲端任務可能仍在執行，請稍後至 WordPress 後台確認是否已發佈。", "error");
            setGeneratingState(false);
            return;
        }

        $.ajax({
            url: wxacgAIParams.ajaxurl,
            type: "GET",
            data: {
                action: "wxacg_poll_task_status",
                nonce: wxacgAIParams.nonce,
                task_id: currentTaskID
            },
            success: function(res) {
                if (res.success) {
                    var data = res.data;
                    terminalScreen.empty();
                    
                    logToTerminal("[當前任務 ID: " + currentTaskID + " 狀態數值: " + data.status + "]");

                    if (data.logs && data.logs.length > 0) {
                        data.logs.forEach(function(line) {
                            var type = "normal";
                            if (line.indexOf("❌") !== -1 || line.indexOf("失敗") !== -1 || line.indexOf("錯誤") !== -1) {
                                type = "error";
                            } else if (line.indexOf("✔") !== -1 || line.indexOf("成功") !== -1 || line.indexOf("完成") !== -1) {
                                type = "success";
                            }
                            // 伺服器 log 本身已含時間戳（如 [05:12:44]），不額外加瀏覽器本機時間避免重複顯示
                            var lineDiv = $("<div class=\"term-line term-line-" + (type === 'error' ? 'err' : (type === 'success' ? 'ok' : 'normal')) + "\">" + "&gt; " + $("<div>").text(line).html() + "</div>");
                            terminalScreen.append(lineDiv);
                        });
                        terminalScreen.scrollTop(terminalScreen[0].scrollHeight);
                    }

                    if (data.status === "success") {
                        stopPolling();
                        logToTerminal("✔ 全盤任務打入最後慶典成功完畢！已安居進您的 WordPress 中了！", "success");
                        setGeneratingState(false);
                    } else if (data.status === "cancelled") {
                        // 使用者主動中止：與執行失敗分開呈現，避免誤以為是系統出錯
                        stopPolling();
                        logToTerminal("🛑 任務已依您的指示中止，未產出文章。", "warn");
                        setGeneratingState(false);
                    } else if (data.status === "failed") {
                        stopPolling();
                        logToTerminal("❌ 這次在雲端伺服器遭致非正常異常中止，請檢閱上述對位錯因報訊。", "error");
                        setGeneratingState(false);
                    }
                } else {
                    logToTerminal("本輪未接收到正確狀態更新...");
                }
            },
            error: function() {
                logToTerminal("偶而斷流獲取連鎖心跳失敗...");
            }
        });
    }
});
