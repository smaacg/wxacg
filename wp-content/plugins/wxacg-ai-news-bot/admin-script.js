/* ==========================================
 * 微笑動漫 AI 新聞管理 AJAX 處理代碼
 * ========================================== */

jQuery(document).ready(function($) {
    let pollingTimer = null;
    let currentTaskID = "";
    let terminalScreen = $("#wxacg_terminal_screen");

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
        } else if (msg.indexOf("⚠️") !== -1) {
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

    // 2. 清除終端日誌畫面
    $("#wxacg_btn_clear_log").on("click", function(e) {
        e.preventDefault();
        terminalScreen.empty().append("<div class=\"term-line\"><span class=\"term-time\">" + getTerminalTime() + "</span> &gt; 日誌紀錄被刷清。等待獲取新消息。</div>");
    });

    // 3. 點選【開始生成報導】發出指令
    $("#wxacg_btn_generate").on("click", function(e) {
        e.preventDefault();
        
        var targetUrl = $("#wxacg_target_url").val().trim();
        var customGlossary = $("#wxacg_custom_glossary").val().trim();
        var targetCategory = $("#wxacg_target_category").val();
        var targetChannel = $("#wxacg_target_channel").val();
        var style = $("#wxacg_ai_style").val() || "default";
        var styleText = $("#wxacg_ai_style option:selected").text() || "1. AI預設";

        if (!targetUrl) {
            alert("提醒：您必須貼上您要轉化的目標海外報導文章網址。");
            $("#wxacg_target_url").focus();
            return;
        }

        logToTerminal("收到下單！組成 JSON 架構並投標發往 GCP 雲端大本營...");
        logToTerminal("指定去向 -> 分類號: " + targetCategory + " | 頻道: " + targetChannel + " | 風格: [" + styleText + "]");
        
        var btn = $("#wxacg_btn_generate");
        btn.prop("disabled", true).text("生成處理中...");

        if (pollingTimer) {
            clearInterval(pollingTimer);
        }

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
                    logToTerminal("正展開 Live 監測，每 2 秒一次接收雲端實景運營工務日誌...");
                    
                    pollingTimer = setInterval(pollTaskStatus, 2000);
                } else {
                    logToTerminal("❌ 無法通報任務： " + res.data.message, "error");
                    btn.prop("disabled", false).text("開始生成報導");
                }
            },
            error: function(xhr, status, error) {
                logToTerminal("❌ 本地通訊斷裂受難： " + error, "error");
                btn.prop("disabled", false).text("開始生成報導");
            }
        });
    });

    // 4. 定期輪詢獲取遠端伺服工作狀況
    function pollTaskStatus() {
        if (!currentTaskID) return;

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
                            var timeStr = "<span class=\"term-time\">" + getTerminalTime() + "</span> ";
                            var lineDiv = $("<div class=\"term-line term-line-" + (type === 'error' ? 'err' : (type === 'success' ? 'ok' : 'normal')) + "\">" + timeStr + "&gt; " + $("<div>").text(line).html() + "</div>");
                            terminalScreen.append(lineDiv);
                        });
                        terminalScreen.scrollTop(terminalScreen[0].scrollHeight);
                    }

                    if (data.status === "success") {
                        clearInterval(pollingTimer);
                        pollingTimer = null;
                        logToTerminal("✔ 全盤任務打入最後慶典成功完畢！已安居進您的 WordPress 中了！", "success");
                        $("#wxacg_btn_generate").prop("disabled", false).text("開始生成報導");
                    } else if (data.status === "failed") {
                        clearInterval(pollingTimer);
                        pollingTimer = null;
                        logToTerminal("❌ 這次在雲端伺服器遭致非正常異常中止，請檢閱上述對位錯因報訊。", "error");
                        $("#wxacg_btn_generate").prop("disabled", false).text("開始生成報導");
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
