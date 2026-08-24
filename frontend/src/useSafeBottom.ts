import { onMounted, onBeforeUnmount, ref } from "vue";

/** 移动端地址栏动态视口补偿：
 *  fixed bottom 元素在手机浏览器（iOS 底部地址栏 / Android URL 栏）滚动时会
 *  因地址栏收起/展开而上下跳动。用 visualViewport 算出地址栏占用的底部高度，
 *  动态抬高 bottom，让元素始终贴可视区底部。桌面端 gap 恒为 0，无副作用。 */
export function useSafeBottom(baseBottom: number) {
  const bottom = ref(baseBottom);
  let vv: VisualViewport | null = null;
  let raf = 0;

  function sync() {
    if (raf) return;
    raf = requestAnimationFrame(() => {
      raf = 0;
      if (!vv) return;
      const gap = Math.max(0, window.innerHeight - (vv.height + vv.offsetTop));
      const next = Math.round(baseBottom + gap);
      if (next !== bottom.value) bottom.value = next;
    });
  }

  onMounted(() => {
    if ("visualViewport" in window) {
      vv = window.visualViewport;
      vv.addEventListener("resize", sync);
      vv.addEventListener("scroll", sync);
    }
    sync();
  });

  onBeforeUnmount(() => {
    if (vv) {
      vv.removeEventListener("resize", sync);
      vv.removeEventListener("scroll", sync);
    }
    if (raf) cancelAnimationFrame(raf);
  });

  return { bottom };
}
