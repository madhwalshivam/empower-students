const { createClient } = require('@supabase/supabase-js');

const supabaseUrl = 'https://nkaqonnblhxfuhmzgnpu.supabase.co';
const supabaseServiceKey = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Im5rYXFvbm5ibGh4ZnVobXpnbnB1Iiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc4MDEzMjk0NiwiZXhwIjoyMDk1NzA4OTQ2fQ.Cump2sJ3Nw9VTlqMVLIehh5SRMhaVMTX0FpswdbqBsk';

async function test() {
  const supabaseAdmin = createClient(supabaseUrl, supabaseServiceKey);
  const userId = '7e34fdbd-8e7e-4bc8-900a-dadef6b50a7f'; // madhwalshivam08@gmail.com

  console.log("Fetching parent...");
  const { data: parent, error: pErr } = await supabaseAdmin
    .from('parents')
    .select('*')
    .eq('id', userId)
    .single();

  console.log("Parent:", parent, "Error:", pErr);

  console.log("Fetching children...");
  const { data: children, error: cErr } = await supabaseAdmin
    .from('children')
    .select('*')
    .eq('parent_id', userId)
    .order('created_at', { ascending: false });

  console.log("Children:", children, "Error:", cErr);

  const childrenList = children || [];

  console.log("Fetching unread feedback...");
  const { data: unreadFeedback, error: fErr } = await supabaseAdmin
    .from('parent_feedback')
    .select('*')
    .eq('parent_id', userId)
    .eq('seen_by_parent', false)
    .order('id', { ascending: false });

  console.log("Unread feedback:", unreadFeedback, "Error:", fErr);

  const selectedCid = childrenList[0]?.id || 0;
  console.log("Selected Cid:", selectedCid);

  let assessmentsList = [];
  if (childrenList[0]) {
    console.log("Fetching assessments for selected child...");
    const { data: assessments, error: aErr } = await supabaseAdmin
      .from('assessments')
      .select('*')
      .eq('child_id', childrenList[0].id)
      .order('completed_at', { ascending: false });
    console.log("Assessments:", assessments, "Error:", aErr);
  }
}

test();
