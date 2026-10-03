/** The signed-in administrator, as shared by HandleInertiaRequests (never the whole model). */
export type User = {
    id: number;
    name: string;
    email: string;
};

export type Auth = {
    user: User | null;
};
