import KnowledgeManager from '@/Components/AI/KnowledgeManager';

/** The standalone knowledge base page. The screen itself lives in the component
 *  so the Smart Bot can render the same one. */
export default function AiKnowledgeBaseShow(props) {
    return <KnowledgeManager {...props} />;
}
